<?php

namespace bdk\XdebugWamp;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * PSR-3 Logger
 */
class ConsoleLogger extends AbstractLogger
{

    protected $levelsOutput = array();

    /**
     * Constructor
     *
     * @param array $levels (optional) levels that will be output
     */
    public function __construct($levels = null)
    {
        if ($levels === null) {
            $levels = $this->validLevels();
        }
        $this->levelsOutput = $levels;
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed         $level   debug, info, notice, warning, error, critical, alert, emergency
     * @param string|object $message message
     * @param array         $context array
     *
     * @return void
     * @throws InvalidArgumentException If invalid level.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function log($level, $message, array $context = array())
    {
    	$this->assertValidLevel($level);
        if (\in_array($level, $this->levelsOutput) === false) {
            return;
        }
        $colorDateTime = "\e[38;5;247m";
        $colorReset = "\e[0m";
        $colorsLevel = array(
            LogLevel::EMERGENCY => "\e[38;5;11;1;4m",
            LogLevel::ALERT => "\e[38;5;226m", // "\e[38;5;10;4m",
            LogLevel::CRITICAL => "\e[38;5;220;1m", // "\e[38;5;1m",
            LogLevel::ERROR => "\e[38;5;220m", // "\e[38;5;88;1m",
            LogLevel::WARNING => "\e[38;5;214;40m",
            LogLevel::NOTICE => "\e[38;5;208m",
            LogLevel::INFO => "\e[38;5;51m",
            LogLevel::DEBUG => '',
        );
        $levelStrLen = \strlen($level);
        $strLenDiff = 9 - $levelStrLen;
        $levelPad = \str_repeat(' ', $strLenDiff);
        echo \sprintf(
            '%s %-9s %s',
            $colorDateTime . \date('Y-m-d H:i:s') . \substr(\microtime(), 1, 8) . $colorReset,
            isset($colorsLevel[$level])
                ? $colorsLevel[$level] . $level . $colorReset . $levelPad
                : $level,
            $message // "\e[38;5;159m"
        ) . "\n";
    }

    /**
     * Check if level is valid
     *
     * @param string $level debug level
     *
     * @return void
     *
     * @throws InvalidArgumentException
     */
    protected function assertValidLevel($level)
    {
        if (!\in_array($level, $this->validLevels())) {
            throw new InvalidArgumentException(\sprintf(
                '%s is not a valid level',
                $level
            ));
        }
    }

    /**
     * Get list of valid levels
     *
     * @return array list of levels
     */
    protected function validLevels()
    {
        return array(
            LogLevel::EMERGENCY,
            LogLevel::ALERT,
            LogLevel::CRITICAL,
            LogLevel::ERROR,
            LogLevel::WARNING,
            LogLevel::NOTICE,
            LogLevel::INFO,
            LogLevel::DEBUG,
        );
    }
}
