<?php

namespace bdk\XdebugWamp;

use bdk\Debug;
use bdk\Debug\Route\WampCrate;
use bdk\XdebugWamp\ConsoleLogger;
use bdk\XdebugWamp\Dbgp;
use bdk\XdebugWamp\DbgpBase;
use bdk\XdebugWamp\DbgpToPhpDebugConsole;
use Evenement\EventEmitter;
use Exception;
use Psr\Log\LogLevel;
use React\EventLoop\Factory as EventLoopFactory;
use React\Promise\Deferred;
use React\Socket\ConnectionInterface;
use React\Socket\Connector as SocketConnector;
use React\Socket\Server as SocketServer;
use Thruway\ClientSession;
use Thruway\Logging\Logger as WampLogger;
use Thruway\Peer\Client as WampClient;
use Thruway\Transport\PawlTransportProvider;

/**
 * Xdebug <--> WAMP proxy
 */
class XdebugWamp extends EventEmitter
{

    protected $cfg = array(
        'wamp' => array(
            'realm' => 'debug',
            'topic' => 'bdk.debug.xdebug',
            'url' => 'ws://127.0.0.1:9090/',
        ),
        'dbgp' => array(
            'host' => '127.0.0.1',
            'port' => '9000',           // port we listen on
                                        //   if we're using a dbgp proxy, this will be need to be a custom port
            'ideKey' => 'XdebugWamp',
        ),
        'dbgpProxy' => array(
            'enabled' => false,
            'uri' => '127.0.0.1:9001',  // we connect to this and tell it what port we're listening on
        ),
        'dbgpRepeat' => array(
            'enabled' => false,
            'uri' => '127.0.0.1:9002',  // port our IDE is listening on
        ),
        'logLevels' => array(
            LogLevel::EMERGENCY,
            LogLevel::ALERT,
            LogLevel::CRITICAL,
            LogLevel::ERROR,
            LogLevel::WARNING,
            LogLevel::NOTICE,
            LogLevel::INFO,
            LogLevel::DEBUG,
        ),
        'logger' => null,
    );
    protected $connectionRepeat = null;
    protected $repeatQueue = array();
    protected $wampClient;
    protected $wampSession;
    protected $logger;
    protected $debug;
    protected $wampCrate;
    private $loop;
    private $dbgpInstances = array();

    /**
     * Constructor
     *
     * @param array $cfg Configuration
     */
    public function __construct($cfg = array())
    {
        if (isset($cfg['logLevels'])) {
            unset($this->cfg['logLevels']);
        }
        $this->cfg = \array_replace_recursive($this->cfg, $cfg);
        $this->converter = new DbgpToPhpDebugConsole();
        $this->debug = new Debug();
        $this->logger = $this->cfg['logger']
            ? $this->cfg['logger']
            : new ConsoleLogger($this->cfg['logLevels']);
        $this->wampCrate = new WampCrate($this->debug);
        unset($this->cfg['logger']);
    }

    /**
     * Publish dbgp message to WAMP
     *
     * @param array    $xmlArray xml structure
     * @param DbgpBase $dbgp     Dbgp instance
     *
     * @return void
     */
    public function onDbgpMessage($xmlArray, DbgpBase $dbgp)
    {
        $command = isset($xmlArray['attribs']['command'])
            ? $xmlArray['attribs']['command']
            : null;
        $status = isset($xmlArray['attribs']['status'])
            ? $xmlArray['attribs']['status']
            : null;
        $xmlArray['attribs'] = \array_diff_key($xmlArray['attribs'], \array_flip(array('xmlns','xmlns:xdebug')));
        if ($xmlArray['name'] === 'init') {
            $appId = $xmlArray['attribs']['appid'];
            $this->dbgpInstances[$appId] = $dbgp;
        } elseif ($status === 'break') {
            $breakInfo = $xmlArray['children']['xdebug:message']['attribs'];
            $file = \str_replace('file://', '', $breakInfo['filename']);
            $line = (int) $breakInfo['lineno'];
            $numLines = 19;
            $sub = (int) \floor($numLines  / 2);
            $begin = \max($line - $sub, 0);
            $info = array(
                'appId' => $dbgp->appId,
                'command' => $command,
                'file' => $file,
                'line' => $line,
                'status' => $status,
                'begin' => $begin,
                'end' => $begin + $numLines,
            );
            $dbgp->send(
                'source',
                array(
                    'f' => $breakInfo['filename'],
                    'b' => $info['begin'], // begin line
                    'e' => $info['end'], // end line
                ),
                null,
                function ($sourceXml) use ($info) {
                    $text = \base64_decode($sourceXml['text']);
                    $lines = \preg_split('/\r?\n/', $text);
                    $lines = \array_slice($lines, 0, $info['end'] - $info['begin']);
                    $lastLine = \array_pop($lines);
                    $lines = \array_map(function ($line) {
                        return $line . "\n";
                    }, $lines);
                    $lines[] = $lastLine;
                    $lineNums = \range($info['begin'], $info['begin'] + \count($lines) - 1);
                    $lines = \array_combine($lineNums, $lines);
                    $this->logger->debug('lines = ' . \json_encode($lines, JSON_PRETTY_PRINT));
                    /*
                    $this->logger->debug(print_r(array(
                        'count' => \count($lines),
                        'begin' => $info['begin'],
                        'end' => $info['end'],
                        'lineNums' => $lineNums,
                    ), true));
                    */
                    // $this->logger->debug('lines = ' . \print_r($lines, true));
                    $this->wampPublish(
                        'log',
                        array('break on', $info['file'], $info['line']),
                        array(
                            'appId' => $info['appId'],
                            'command' => $info['command'],
                            'status' => $info['status'],
                            'detectFiles' => true,
                            'file' => $info['file'],
                            'line' => $info['line'],
                        )
                    );
                    $this->wampPublish(
                        'log',
                        array(),
                        array(
                            'appId' => $info['appId'],
                            'context' => $lines,
                            'line' => $info['line'],
                        )
                    );
                }
            );
            // end break
        } elseif ($status === 'stopping') {
            $this->logger->notice('dbgp stopping -> closing connection');
            $this->wampPublish(
                'xdebug',
                array(),
                \array_merge(
                    $xmlArray['attribs'],
                    array(
                        'appId' => $dbgp->appId,
                    )
                ),
            );
            $dbgp->close();
        } elseif ($command === 'context_get') {
            $values = array();
            foreach ($xmlArray['children']['property'] as $property) {
                $name = $property['attribs']['name'];
                $values[$name] = $this->converter->convert($property);
            }
            $label = 'context';
            /*
                0: local vars
                1: global vars
                2: class context?
            */
            if ($xmlArray['attribs']['context'] === '1') {
                $label = 'globals';
                $move = array('argv','argc');
                foreach ($move as $key) {
                    if (!isset($values['$GLOBALS'][$key])) {
                        continue;
                    }
                    $val = $values['$GLOBALS'][$key];
                    $values['$GLOBALS']['_SERVER'][$key] = $val;
                    $values['$_SERVER'][$key] = $val;
                    unset($values['$GLOBALS'][$key]);
                }
                $this->sortGlobals($values['$_SERVER']);
                $this->sortGlobals($values['$GLOBALS']);
                $this->sortGlobals($values['$GLOBALS']['_SERVER']);
            } elseif ($xmlArray['attribs']['context'] === '2') {
                $label = 'class context';
            }
            $this->wampPublish(
                'log',
                array($label, $values),
                array(
                    'appId' => $dbgp->appId,
                    'command' => $command,
                    'status' => $status,
                )
            );
        } elseif (\in_array($command, array('property_get', 'property_value'))) {
            $this->logger->debug('fullname = ' . $property['attribs']['fullname']);
            $this->logger->debug($command . ' response: ' . \json_encode($xmlArray, JSON_PRETTY_PRINT));
            $property = $xmlArray['children']['property'][0];
            $this->wampPublish(
                'xdebug',
                array(
                    $this->converter->convert($property),
                ),
                array(
                    'appId' => $dbgp->appId,
                    'command' => $command,
                    'fullname' => $property['attribs']['fullname'],
                )
            );
        }
    }

    /**
     * Start the "proxy"
     *
     * @return void
     */
    public function run()
    {
        $this->init();
        $this->wampClient->start();
        $this->loop->run();
    }

    /**
     * Act as a middleman / proxy for an IDE
     *
     * @param ConnectionInterface $connection Connection to debugger
     *
     * @return \React\Promise\PromiseInterface resolves with bool
     */
    protected function dbgpRepeat(ConnectionInterface $connection)
    {
        $deferred = new Deferred();
        if ($this->cfg['dbgpRepeat']['enabled'] === false) {
            $deferred->resolve(false);
            return $deferred->promise();
        }
        $this->logger->info('attempting to connect to ' . $this->cfg['dbgpRepeat']['uri']);
        $connector = new SocketConnector($this->loop, array(
            'timeout' => 1,
        ));
        $connector->connect($this->cfg['dbgpRepeat']['uri'])->then(
            function (ConnectionInterface $connectionRepeat) use ($connection, $deferred) {
                $this->logger->notice('connected to ' . $this->cfg['dbgpRepeat']['uri']);
                $this->connectionRepeat = $connectionRepeat;
                while ($this->repeatQueue) {
                    $data = \array_shift($this->repeatQueue);
                    $connectionRepeat->write($data);
                }
                $connectionRepeat->on('data', function ($data) use ($connection) {
                    $this->logger->info('dbgpRepeat <- ' . $data);
                    $connection->write($data);
                });
                $connectionRepeat->on('close', function () {
                    $this->logger->info('connectionRepeat closed');
                });
                $deferred->resolve(true);
            },
            function (Exception $exception) use ($deferred) {
                $this->connectionRepeat = false;
                $this->repeatQueue = array();
                $this->logger->error($exception->getMessage());
                $deferred->resolve(false);
            }
        );
        /*
            We may start receiving data before we've connected to repeat
        */
        $connection->on('data', function ($data) {
            \preg_match('/^<response[^>]+transaction_id="([^"]+)"/m', $data, $matches);
            $transactionId = isset($matches[1]) ? $matches[1] : null;
            if ($this->connectionRepeat === null) {
                $this->logger->debug('dbgpRepeat enqueing ' . $transactionId);
                $this->repeatQueue[] = $data;
            } elseif ($this->connectionRepeat) {
                $this->logger->info('dbgpRepeat -> ' . $transactionId . ' response');
                $this->connectionRepeat->write($data);
            }
        });
        return $deferred->promise();
    }

    /**
     * initialize
     *
     * @return void
     */
    protected function init()
    {
        $this->loop = EventLoopFactory::create();
        $this->initWamp();
        $this->initDbgpProxy();
        $this->initDbgp();
        $this->on('dbgpConnection', function (Dbgp $dbgp) {
            $dbgp->on('message', array($this, 'onDbgpMessage'));
            $dbgp->on('close', function ($dbgp) {
                unset($this->dbgpInstances[$dbgp->appId]);
            });
        });
    }

    /**
     * initialize dbgp
     *
     * @return void
     */
    protected function initDbgp()
    {
        $this->logger->notice(__METHOD__);
        $uri = $this->cfg['dbgp']['host'] . ':' . $this->cfg['dbgp']['port'];
        $server = new SocketServer($uri, $this->loop);
        $server->on('connection', function (ConnectionInterface $connection) {
            $this->logger->notice('received connection on port ' . $this->cfg['dbgp']['port']);
            /*
                we will store $dbgp in $this->dbgpInstances once we receive init
            */
            $dbgp = new Dbgp($connection, $this->logger);
            $dbgp->pause();
            $this->dbgpRepeat($connection)->done(function () use ($dbgp) {
                $this->logger->notice('dbgpConnection');
                $this->emit('dbgpConnection', array($dbgp));
                $dbgp->resume();
            });
        });
    }

    /**
     * Connect to proxy and tell it what port we're listening on
     *
     * @return void
     */
    protected function initDbgpProxy()
    {
        if ($this->cfg['dbgpProxy']['enabled'] === false) {
            return;
        }
        $this->logger->notice(__METHOD__);
        $connector = new SocketConnector($this->loop);
        $connector->connect($this->cfg['dbgpProxy']['uri'])->then(function (ConnectionInterface $connection) {
            $dbgpProxy = new DbgpBase(
                $connection,
                $this->logger
            );
            $dbgpProxy->send('proxyinit', array(
                'p' => $this->cfg['dbgp']['port'],
                'k' => $this->cfg['dbgp']['ideKey'],
            ));
        });
    }

    /**
     * initialize wamp
     *
     * @return void
     */
    protected function initWamp()
    {
        $this->logger->notice(__METHOD__);
        WampLogger::set($this->logger);
        $this->wampClient = new WampClient($this->cfg['wamp']['realm'], $this->loop);
        $this->wampClient->addTransportProvider(new PawlTransportProvider($this->cfg['wamp']['url']));
        $this->wampClient->on('open', function (ClientSession $session) {
            $this->wampSession = $session;
            $this->wampSession->subscribe('bdk.debug.xdebug', function ($args) {
                $this->logger->info('wamp <- ' . \json_encode($args, JSON_PRETTY_PRINT));
                $args = \array_replace(array(
                    null, // appId
                    null, // cmd
                    array(), // params
                    null, // data
                ), $args);
                $appId = $args[0];
                $dbgp = $this->dbgpInstances[$appId];
                $dbgp->send(
                    $args[1],
                    \json_decode(\json_encode($args[2]), true), // Thruway decodes args as stdClass.. we want array
                    $args[3]
                );
            });
        });
        // don't start wampClient yet
        // $this->wampClient->start();
    }

    /**
     * Sort globals by key
     *
     * @param array $array Array to sort
     *
     * @return void
     */
    protected function sortGlobals(&$array)
    {
        $order = array(
            '$_COOKIE',
            '$_ENV',
            '$_FILES',
            '$_GET',
            '$_POST',
            '$_REQUEST',
            '$_SERVER',
            '$GLOBALS',
            '_COOKIE',
            '_ENV',
            '_FILES',
            '_GET',
            '_POST',
            '_REQUEST',
            '_SERVER',
            'GLOBALS',
        );
        \uksort($array, function ($keyA, $keyB) use ($order) {
            $aPos = \array_search($keyA, $order);
            $bPos = \array_search($keyB, $order);
            if ($aPos === false && $bPos === false) {
                // both items are dont cares
                // return 0;                       // a == b
                $keyA = \strtr($keyA, '_', "\x1F");
                $keyB = \strtr($keyB, '_', "\x1F");
                return \strcasecmp($keyA, $keyB);
            }
            if ($aPos === false) {
                // a is a dont care
                return 1;               // $a > $b
            }
            if ($bPos === false) {
                // b is a dont care
                return -1;              // $a < $b
            }
            return $aPos < $bPos ? -1 : 1;
        });
    }

    /**
     * Publish to WAMP topic
     *
     * @param string $method PhpDebugConsole method
     * @param array  $args   Arguments
     * @param array  $meta   Meta values
     *
     * @return void
     */
    protected function wampPublish($method, $args, $meta)
    {
        $topic = $this->cfg['wamp']['topic'];
        foreach ($args as $i => $v) {
            $args[$i] = $this->debug->abstracter->crate($v, $method);
        }
        $args = $this->wampCrate->crate($args);
        $this->wampSession->publish($topic, array(
            $method,
            $args,
            $meta
        ));
    }
}
