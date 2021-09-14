<?php

namespace bdk\XdebugWamp;

use bdk\XdebugWamp\Xml;
use Evenement\EventEmitter;
use Psr\Log\LoggerInterface;
use React\Socket\ConnectionInterface;

/**
 *
 */
class DbgpBase extends EventEmitter
{

    public $appId = null;  // set when init message received

    /** @var ConnectionInterface */
    protected $connection;

    /** @var LoggerInterface */
    protected $logger;

    protected $commandCounter = 0;
    protected $requestIdIncl = false;
    protected $requestIdPrefix = '';

    /** @var Deferred[] */
    private $callables = array();

    /**
     * Constructor
     *
     * @param ConnectionInterface $connection React ConnectionInterface
     * @param LoggerInterface     $logger     Psr3 LoggerInterface
     */
    public function __construct(ConnectionInterface $connection, LoggerInterface $logger)
    {
    	$this->logger = $logger;
		$this->handleConnection($connection);
		$this->on('message', array($this, 'onMessage'));
    }

    /**
     * Close connection
     *
     * @return void
     */
    public function close()
    {
        $this->connection->close();
    }

    /**
     * Pauses reading incoming data events.
     *
     * @return void
     */
    public function pause()
    {
        $this->connection->pause();
    }

    /**
     * Resumes reading incoming data events.
     *
     * @return void
     */
    public function resume()
    {
        $this->connection->resume();
    }

    /**
     * Send command to debugger
     *
     * @param string   $name command name
     * @param array    $args command arguments
     * @param string   $data additional data
     * @param callable $callable optional callable to be invoked upon response
     *
     * @return void
     */
    public function send($name, $args = array(), $data = null, callable $callable = null)
    {
        if ($this->requestIdIncl) {
            $args['i'] = $this->requestIdPrefix . (++$this->commandCounter);
        }
        $cmd = $this->formatCommand($name, $args, $data);
        $this->logger->info(\sprintf(
            '%s %s -> %s',
            \get_called_class(),
            $this->appId,
            $cmd
        ));
        $this->connection->write($cmd);
        $this->callables[$args['i']] = $callable;
    }

    /**
     * Handle connection from debugger
     *
     * @param ConnectionInterface $connection Conection to debugger
     *
     * @return void
     */
    protected function handleConnection(ConnectionInterface $connection)
    {
	    $this->logger->notice(\get_called_class() . ' connected');
	    $this->connection = $connection;
        $len = null;
        $data = '';
	    $connection->on('data', function ($buffer) use (&$data, &$len) {
            $this->logger->debug(\sprintf(
                '%s %s data received %s bytes',
                \get_called_class(),
                $this->appId ?: '??',
                \strlen($buffer)
            ));
            $data .= $buffer;
            while ($data) {
                if ($len === null) {
                    \preg_match('/^(\d+)\x00(.*)$/s', $data, $matches);
                    $len = (int) $matches[1];
                    $data = $matches[2];
                }
                if (\strlen($data) <= $len) {
                    // big message... need more buffer
                    return;
                }
                $xml = \substr($data, 0, $len);
                $data = \substr($data, $len + 1); // skip over msg + \x00
                $len = null;
                /*
                    Now emit the message
                */
                $xml = Xml::toArray($xml, array(
                    'alwaysAsArray' => array('property'),
                ));
                $transactionId = isset($xml['attribs']['transaction_id'])
                    ? $xml['attribs']['transaction_id']
                    : null;
                if (isset($this->callables[$transactionId])) {
                    $this->callables[$transactionId]($xml);
                    unset($this->callables[$transactionId]);
                }
                if ($xml['name'] === 'init') {
                    $this->appId = $xml['attribs']['appid'];
                }
                $this->emit('message', array($xml, $this));
            }
	    });
	    $connection->on('close', function () {
	    	$this->logger->notice(\sprintf(
                '%s %s connection closed',
                \get_called_class(),
                $this->appId
            ));
            $this->emit('close', array($this));
	    });
	    // $this->send('proxyinit');
    }

    /**
     * Format command
     *
     * @param string $name command name
     * @param array  $args command arguments
     * @param string $data additional data
     *
     * @return string
     */
    private function formatCommand($name, $args = array(), $data = null)
    {
        $command = array($name);
        // command [SPACE] [arguments] [SPACE] -- base64(data) [NULL]
        foreach ($args as $k => $v) {
            if (\is_bool($v)) {
                $v = (int) $v;
            } elseif (\preg_match('/[ \x00]/', $v)) {
                $v = \str_replace(array('"', "\x00", '\\'), array('\"', '\0', '\\\\'), $v);
                $v = '"' . $v . '"';
            }
            $command[] = ' -' . $k . ' ' . $v;
        }
        if ($data) {
            $command[] = ' --' . \base64_encode($data);
        }
        $command[] = "\x00";
        return \implode('', $command);
    }

    /**
     * Handle incoming message from debugger
     *
     * @param array    $xmlArray DBGP message XML structure
     * @param DbgpBase $dbgp     DBGP instance
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function onMessage($xmlArray, DbgpBase $dbgp)
    {
        unset(
        	$xmlArray['attribs']['xmlns'],
        	$xmlArray['attribs']['xmlns:xdebug']
        );
        if ($xmlArray['name'] === 'response') {
            if (
                isset($xmlArray['attribs']['status']) && \in_array($xmlArray['attribs']['status'], array('break'))
                || isset($xmlArray['attribs']['command']) && \in_array($xmlArray['attribs']['command'], array('source'))
            ) {
                $this->logger->info(\sprintf(
                    '%s %s <- response %s',
                    \get_called_class(),
                    $this->appId,
                    \json_encode($xmlArray, JSON_PRETTY_PRINT)
                ));
            } else {
            	$this->logger->info(\sprintf(
                    '%s %s <- response attribs %s',
                    \get_called_class(),
                    $this->appId,
                    \json_encode($xmlArray['attribs'], JSON_PRETTY_PRINT)
                ));
            }
            return;
        }
        $this->logger->info(\get_called_class() . ' <- ' . \json_encode($xmlArray, JSON_PRETTY_PRINT));
    }
}
