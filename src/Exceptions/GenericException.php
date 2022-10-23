<?php

namespace SubtitleToolbox\Exceptions;

abstract class GenericException extends \RuntimeException
{
    /**
     * GenericException constructor.
     *
     * @param $message
     */
    public function __construct($message)
    {
        $code = "[Error #" . $this->getErrorCode() . "] ";

        return parent::__construct($this->getClassName() . " (Error #$code): " . $message, $this->getErrorCode());
    }


    /**
     * Returns the name of the Exception class without its full namespace
     *
     * @return string
     */
    private function getClassName(): string
    {
        $classNameArray = explode('\\', get_class($this));

        return array_pop($classNameArray);
    }


    abstract function getErrorCode(): int;
}
