<?php

namespace EmailTester;

class SyncClient
{
    private $stream;

    public function connect($server)
    {
        $errno = $errstr = null;
        $this->stream = stream_socket_client($server, $errno, $errstr, 1);

        if (fwrite($this->stream, "helo hi\r\n") === false) {
            throw new \RuntimeException("Error while sending helo");
        }

        if (fwrite($this->stream, "mail from: <test." . mt_rand(0, 99999) . "@example.com>\r\n") === false) {
            throw new \RuntimeException("Error while sending from");
        }
    }

    public function checkEmail($record)
    {
        if (fwrite($this->stream, "rcpt to: <{$record}>\r\n") === false) {
            throw new \RuntimeException("Error while sending rcpt");
        }

        while (true) {
            $response = fgets($this->stream);

            $code = intval(substr($response, 0, 3));
            $sub  = intval($response[4] . $response[6] . $response[8]);

            if (($code === 220 || $code === 250) && $sub === 0) {
                continue;
            }

            if ($code === 250 && $sub === 210) {
                continue;
            }

            if ($code === 250 && $sub === 215) {
                return true;
            } else if ($code === 452 && $sub === 453) {
                return false;
            } else {
                while (substr($response, -7, 5) !== 'gsmtp') {
                    $response = fgets($this->stream);
                }

                return false;
            }
        }

        return false;
    }

    public function disconnect()
    {
        fclose($this->stream);
    }
}