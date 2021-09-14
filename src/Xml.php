<?php

namespace bdk\XdebugWamp;

/**
 * Convert Xml to array structure
 */
class Xml
{

    protected static $pointer;
    protected static $pointers = array();

    /** @var XMLReader */
    protected static $xml;

    protected static $cfgDefault = array(
        'alwaysAsArray' => array(),
    );
    protected static $cfg = array();

    /**
     * Convert XML to array
     *
     * @param string|\XMLReader $xml XML to convert
     * @param array             $cfg [description]
     *
     * @return [type] [description]
     */
    public static function toArray($xml, $cfg = array())
    {
        if (\is_string($xml)) {
            $xmlReader = new \XMLReader();
            $xmlReader->xml($xml);
            self::$xml = $xmlReader;
        }
        self::$cfg = \array_replace(self::$cfgDefault, $cfg);
        if (!(self::$xml instanceof \XMLReader)) {
            \trigger_error('XMLReader instance expected');
            return false;
        }
        $return = array(
            'children' => array(),
        );
        self::$pointer = &$return;
        self::$pointers = array( &self::$pointer );
        while (self::$xml->read()) {
            if (self::$xml->nodeType === \XMLReader::END_ELEMENT) {
                self::elementEnd();
            } elseif (\in_array(self::$xml->nodeType, array(\XMLReader::TEXT, \XMLReader::CDATA))) {
                self::$pointer['children'][] = array(
                    'text' => \trim(self::$xml->value),
                );
            } elseif (self::$xml->nodeType === \XMLReader::ELEMENT) {
                self::elementStart();
            }
        }
        $name = \array_keys($return['children'])[0];
        $return = $return['children'][$name];
        $return = \array_merge(
            array('name' => $name),
            $return
        );
        return $return;
    }

    /**
     * Close xml element
     *
     * @return void
     */
    protected static function elementEnd()
    {
        \array_pop(self::$pointers);
        if (\array_keys(self::$pointer['children']) === array(0) && \array_keys(self::$pointer['children'][0]) === array('text')) {
            self::$pointer['text'] = self::$pointer['children'][0]['text'];
            unset(self::$pointer['children']);
            if (empty(self::$pointer['attribs'])) {
                self::$pointer = self::$pointer['text'];
            }
        }
        self::$pointer = &self::$pointers[ \count(self::$pointers) - 1 ];
    }

    /**
     * New xml element encountered
     *
     * @return void
     */
    protected static function elementStart()
    {
        $name = self::$xml->name;
        $node = array(
            // 'name' => $name,
            'attribs'   => array(),
            'children' => array(),
            // 'text' => null,
        );
        if (self::$xml->hasAttributes) {
            while (self::$xml->moveToNextAttribute()) {
                $node['attribs'][self::$xml->name] = self::$xml->value;
            }
        }
        if (isset(self::$pointer['children'][$name])) {
            if (!\is_array(self::$pointer['children'][$name]) || !\array_key_exists(0, self::$pointer['children'][$name])) {
                self::$pointer['children'][$name] = array(
                    self::$pointer['children'][$name],
                );
            }
            self::$pointer['children'][$name][] = $node;
            $count = \count(self::$pointer['children'][$name]);
            self::$pointer = &self::$pointer['children'][ $name ][ $count - 1 ];
        } elseif (\in_array($name, self::$cfg['alwaysAsArray'])) {
            if (!isset(self::$pointer['children'][$name])) {
                self::$pointer['children'][$name] = array();
            }
            self::$pointer['children'][$name][] = $node;
            $count = \count(self::$pointer['children'][$name]);
            self::$pointer = &self::$pointer['children'][ $name ][ $count - 1 ];
        } else {
            self::$pointer['children'][$name] = $node;
            self::$pointer = &self::$pointer['children'][ $name ];
        }
        // $pointer = &$pointer['children'][ count($pointer['children']) - 1 ];
        self::$pointers[] = &self::$pointer;
    }
}
