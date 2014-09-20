<?php

namespace EmailTester;

use LogicException;
use React\EventLoop\LoopInterface;
use RuntimeException;
use UnexpectedValueException;

class AsyncClient
{
    const STATE_DISCONNECTED = 1;
    const STATE_BUSY = 2;
    const STATE_IDLE = 4;

    private $errno;
    private $errstr;
    private $stream;
    /** @var \React\EventLoop\LoopInterface */
    private $loop;
    private $record;
    private $state;
    /** @var callable */
    private $onSuccess;
    /** @var callable */
    private $onFailure;

    public function __construct(LoopInterface $loop, callable $onSuccess, callable $onFailure)
    {
        $this->loop  = $loop;
        $this->state = self::STATE_DISCONNECTED;

        $this->onSuccess = $onSuccess;
        $this->onFailure = $onFailure;
    }

    public function connect($server)
    {
        $this->state = self::STATE_BUSY;

        $this->stream = stream_socket_client($server, $this->errno, $this->errstr);

        $this->loop->addReadStream($this->stream, [$this, 'process']);

        if (fwrite($this->stream, "helo hi\r\n") === false) {
            $this->state = self::STATE_DISCONNECTED;
            $this->onFailure->__invoke($this->record, "Error while sending helo");
            echo ($this->errno . ': ' . $this->errstr) . PHP_EOL;
            return;
        }

        if (fwrite($this->stream, "mail from: <test." . mt_rand(0, 99999) . "@example.com>\r\n") === false) {
            $this->state = self::STATE_DISCONNECTED;
            $this->onFailure->__invoke($this->record, "Error while sending from");
            echo ($this->errno . ': ' . $this->errstr) . PHP_EOL;
            return;
        }
    }

    public function checkEmail(array $record)
    {
        if ($this->state === self::STATE_IDLE) {
            $this->state = self::STATE_BUSY;

            $this->record = $record;

            if (fwrite($this->stream, "rcpt to: <{$this->record['email']}>\r\n") === false) {
                $this->state = self::STATE_DISCONNECTED;
                $this->onFailure->__invoke($this->record, "Error while sending rcpt");
                echo ($this->errno . ': ' . $this->errstr) . PHP_EOL;
                return;
            }
        }
    }

    public function process($stream, $loop)
    {
        $response = fgets($stream);

        if ($response === false) {
            $this->state = self::STATE_DISCONNECTED;
            $this->onFailure->__invoke($this->record, "Error while reading data");
            echo ($this->errno . ': ' . $this->errstr) . PHP_EOL;
            return;
        }

        $code = intval(substr($response, 0, 3));
        $sub  = intval($response[4] . $response[6] . $response[8]);

        if (($code === 220 || $code === 250) && $sub === 0) {
            return;
        }

        if ($code === 250 && $sub === 210) {
            $this->state = self::STATE_IDLE;
            return;
        }

        if ($this->record === null) {
            throw new LogicException("Email not set.");
        }

        if ($code === 250 && $sub === 215) {
            $this->onSuccess->__invoke($this->record);
            $this->state = self::STATE_IDLE;
        } else if ($code === 452 && $sub === 453) {
            $this->loop->removeReadStream($this->stream);
            fclose($this->stream);
            $this->onFailure->__invoke($this->record, "limit reached");
            $this->state = self::STATE_DISCONNECTED;
        } else {
            $this->onFailure->__invoke($this->record, false);

            while (substr($response, -7, 5) !== 'gsmtp') {
                $response = fgets($stream);
            }

            $this->state = self::STATE_IDLE;
        }
    }

    /**
     * @return int
     */
    public function getState()
    {
        return $this->state;
    }
}