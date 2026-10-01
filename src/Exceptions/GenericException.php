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
        parent::__construct($this->getClassName() . " (Error #{$this->getErrorCode()}): " . $message, $this->getErrorCode());
    }


    /**
     * Returns the name of the Exception class without its full namespace
     *
     * @return string
     */
    private function getClassName(): string
    {
        $classNameArray = explode('\\', static::class);

        return array_pop($classNameArray);
    }


    abstract public function getErrorCode(): int;
}
