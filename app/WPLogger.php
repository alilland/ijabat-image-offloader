<?php
/**
 * PHP version 8.2.4
 *
 * @category WordPress_Plugin
 * @package  IjabatImageOffloader
 * @author   Ijabat Tech Solutions, LLC <info@ijabat.org>
 * @license  https://www.gnu.org/licenses/gpl-2.0.html GPL2+
 * @link     https://ijabat.org
 */

namespace IjabatImageOffloader;

/**
 * Logging for Wordpress Development
 *
 * @category Helpers
 * @package  IjabatImageOffloader
 * @author   Ijabat Tech Solutions, LLC <info@ijabat.org>
 * @license  https://www.gnu.org/licenses/gpl-2.0.html GPL2+
 * @link     https://ijabat.org
 */
class WPLogger
{
    const DEBUG = 0;
    const INFO = 1;
    const WARN = 2;
    const ERROR = 3;
    const FATAL = 4;

    private static $_levelNames = [
      self::DEBUG => 'DEBUG',
      self::INFO => 'INFO',
      self::WARN => 'WARN',
      self::ERROR => 'ERROR',
      self::FATAL => 'FATAL',
    ];

    // private $_minLevel;
    private int $_minLevel;

    /**
     * Class constructor for the logger.
     *
     * @param int $_level The minimum log level required to log messages.
     */
    public function __construct(int $_level = self::DEBUG)
    {
        $this->_minLevel = $_level;
    }

    /**
     * Logger function that all the public functions leverage
     * to leverage the default error_log
     *
     * @param int    $level   The log level.
     * @param string $message The message to log.
     *
     * @return void
     */
    private function _log(int $level, string $message): void
    {
        if ($level < $this->_minLevel) {
            return;
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $levelName = self::$_levelNames[$level];
        error_log("[IjabatS3] [{$timestamp}] {$levelName}: {$message}");
    }

    /**
     * Debug logger.
     *
     * @param string $msg The debug message to log.
     *
     * @return void
     */
    public function debug(string $msg): void
    {
        $this->_log(self::DEBUG, $msg);
    }

    /**
     * Info logger.
     *
     * @param string $msg The debug message to log.
     *
     * @return void
     */
    public function info(string $msg): void
    {
        $this->_log(self::INFO, $msg);
    }

    /**
     * Warn logger.
     *
     * @param string $msg The debug message to log.
     *
     * @return void
     */
    public function warn(string $msg): void
    {
        $this->_log(self::WARN, $msg);
    }

    /**
     * Error logger.
     *
     * @param string $msg The debug message to log.
     *
     * @return void
     */
    public function error(string $msg): void
    {
        $this->_log(self::ERROR, $msg);
    }

    /**
     * Fatal logger.
     *
     * @param string $msg The debug message to log.
     *
     * @return void
     */
    public function fatal(string $msg): void
    {
        $this->_log(self::FATAL, $msg);
    }
}
