<?php

namespace think\addons;

use think\Exception;

/* *
 * Plugin exception handling class
 * @package think\\addons */
class AddonException extends Exception
{

    public function __construct($message, $code, $data = '')
    {
        $this->message  = $message;
        $this->code     = $code;
        $this->data     = $data;
    }

}
