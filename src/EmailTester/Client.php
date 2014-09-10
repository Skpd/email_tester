<?php

namespace EmailTester;

use LogicException;
use React\EventLoop\LoopInterface;
use RuntimeException;
use UnexpectedValueException;

class Client
{
    const STATE_DISCONNECTED = 1;
    const STATE_BUSY = 2;
    const STATE_IDLE = 4;

    private $stream;
    /** @var \React\EventLoop\LoopInterface */
    private $loop;
    private $email;
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
        $this->stream = stream_socket_client($server);

        $this->loop->addReadStream($this->stream, [$this, 'process']);

        $this->state = self::STATE_BUSY;

        if (fwrite($this->stream, "helo hi\r\n") === false) {
            $this->state = self::STATE_DISCONNECTED;
            $this->onFailure->__invoke($this->email, "Error while sending data");
            return;
        }

        if (fwrite($this->stream, "mail from: <test." . mt_rand(0, 99999) . "@example.com>\r\n") === false) {
            $this->state = self::STATE_DISCONNECTED;
            $this->onFailure->__invoke($this->email, "Error while sending data");
            return;
        }
    }

    public function checkEmail($email)
    {
        if ($this->state === self::STATE_IDLE) {
            $this->state = self::STATE_BUSY;

            $this->email = trim($email);

            if (fwrite($this->stream, "rcpt to: <{$this->email}>\r\n") === false) {
                $this->state = self::STATE_DISCONNECTED;
                $this->onFailure->__invoke($this->email, "Error while sending data");
                return;
            }
        }
    }

    public function process($stream, $loop)
    {
        if ($stream !== $this->stream || $loop !== $this->loop) {
            throw new RuntimeException("Incorrect arguments to process.");
        }

        $response = fgets($this->stream);

        if ($response === false) {
            $this->state = self::STATE_DISCONNECTED;
            $this->onFailure->__invoke($this->email, "Error while sending data");
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

        if ($this->email === null) {
            throw new LogicException("Email not set.");
        }

        if ($code === 250 && $sub === 215) {
            $this->state = self::STATE_IDLE;
            $this->onSuccess->__invoke($this->email);
        } else if ($code === 550 && $sub === 511) {
            $this->state = self::STATE_IDLE;
            $this->onFailure->__invoke($this->email, "$code <$sub>");
            fgets($this->stream);
            fgets($this->stream);
            fgets($this->stream);
        } else if ($code === 550 && $sub === 521) {
            $this->state = self::STATE_IDLE;
            $this->onFailure->__invoke($this->email, "address disabled");
        } else if ($code === 452 && $sub === 422) {
            $this->state = self::STATE_IDLE;
            $this->onFailure->__invoke($this->email, "address not found");
        } else if ($code === 555 && $sub === 552) {
            $this->state = self::STATE_IDLE;
            $this->onFailure->__invoke($this->email, "$code <$sub>");
        } else if ($code === 553 && $sub === 512) {
            $this->state = self::STATE_IDLE;
            $this->onFailure->__invoke($this->email, "$code <$sub>");
        } else if ($code === 451 && $sub === 430) {
            $this->state = self::STATE_IDLE;
            $this->onFailure->__invoke($this->email, "$code <$sub>");
        } else if ($code === 552 && $sub === 522) {
            $this->state = self::STATE_IDLE;
            $this->onFailure->__invoke($this->email, "$code <$sub>");
        } else if ($code === 452 && $sub === 453) {
            $this->state = self::STATE_DISCONNECTED;
            $this->loop->removeReadStream($this->stream);
            $this->onFailure->__invoke($this->email, "limit reached");
        } else {
            $this->state = self::STATE_IDLE;
            $this->onFailure->__invoke($this->email, "$code <$sub>: $response");
//            throw new UnexpectedValueException("$code <$sub>: $response");
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