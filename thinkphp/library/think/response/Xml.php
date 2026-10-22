<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think\response;

use think\Collection;
use think\Model;
use think\Response;

class Xml extends Response
{
    // output parameters
    protected $options = [
        // root node name
        'root_node' => 'think',
        // Root element attributes
        'root_attr' => '',
        // Element name for numerically indexed items
        'item_node' => 'item',
        // Attribute name used to store numeric item keys
        'item_key'  => 'id',
        // XML character encoding
        'encoding'  => 'utf-8',
    ];

    protected $contentType = 'text/xml';

    /* *
 * Encode response data as XML
 * @access protected
 * @param mixed $data Data to encode
 * @return string */
    protected function output($data)
    {
        // Encode response data using the configured XML options
        return $this->xmlEncode($data, $this->options['root_node'], $this->options['item_node'], $this->options['root_attr'], $this->options['item_key'], $this->options['encoding']);
    }

    /* *
 * XML encoding
 * @param mixed $data
 * @param string $root Root element name
 * @param string $item Element name for numerically indexed items
 * @param string|array $attr Root element attributes
 * @param string $id Attribute name used to store numeric item keys
 * @param string $encoding XML character encoding
 * @return string */
    protected function xmlEncode($data, $root, $item, $attr, $id, $encoding)
    {
        if (is_array($attr)) {
            $array = [];
            foreach ($attr as $key => $value) {
                $array[] = "{$key}=\"{$value}\"";
            }
            $attr = implode(' ', $array);
        }
        $attr = trim($attr);
        $attr = empty($attr) ? '' : " {$attr}";
        $xml  = "<?xml version=\"1.0\" encoding=\"{$encoding}\"?>";
        $xml .= "<{$root}{$attr}>";
        $xml .= $this->dataToXml($data, $item, $id);
        $xml .= "</{$root}>";
        return $xml;
    }

    /* *
 * Encode data as XML elements
 * @param mixed $data
 * @param string $item Element name for numerically indexed items
 * @param string $id Attribute name used to store numeric item keys
 * @return string */
    protected function dataToXml($data, $item, $id)
    {
        $xml = $attr = '';

        if ($data instanceof Collection || $data instanceof Model) {
            $data = $data->toArray();
        }

        foreach ($data as $key => $val) {
            if (is_numeric($key)) {
                $id && $attr = " {$id}=\"{$key}\"";
                $key         = $item;
            }
            $xml .= "<{$key}{$attr}>";
            $xml .= (is_array($val) || is_object($val)) ? $this->dataToXml($val, $item, $id) : $val;
            $xml .= "</{$key}>";
        }
        return $xml;
    }
}
