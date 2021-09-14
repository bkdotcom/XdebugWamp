<?php

namespace bdk\XdebugWamp;

use bdk\Debug\Abstraction\Abstracter;
use bdk\Debug\Abstraction\Abstraction;
use bdk\Debug\Abstraction\AbstractObject;

/**
 * Convert context info into PHPDebugConsole structure
 */
class DbgpToPhpDebugConsole
{

	/**
	 * Convert xdebug/dbgp property stucture to PHPDebugConsole structure
	 *
	 * @param array $prop "property" structure
	 *
	 * @return mixed
	 */
	public static function convert($prop)
	{
		switch ($prop['attribs']['type']) {
			case 'array':
				return self::convertArray($prop);
			case 'bool':
				return (bool) $prop['text'];
			case 'int':
				return (int) $prop['text'];
			case 'float':
				return self::convertFloat($prop);
			case 'null':
				return null;
			case 'object':
				return self::convertObject($prop);
			case 'resource':
				return new Abstraction(Abstracter::TYPE_RESOURCE, array(
					'value' => $prop['text'],
				));
			case 'string':
				return self::getValue($prop);
			case 'default':
				return 'unknown type: ' . $prop['attribs']['type'];
		}
	}

	/**
	 * Convert xdebug/dbgp array structure to PHPDebugConsole array
	 *
	 * @param array $prop Dbgp "property" structure
	 *
	 * @return array
	 */
	protected static function convertArray($prop)
	{
		$attribs = $prop['attribs'];
		if (!empty($attribs['recursive'])) {
			return Abstracter::RECURSION;
		}
		if ($attribs['numchildren'] === '0') {
			return array();
		}
		if (empty($prop['children'])) {
			return new Abstraction(Abstracter::TYPE_ARRAY, array(
				'isMaxDepth' => true,
				'value' => array(),
				'attribs' => array(
					'data-fullname' => self::getFullname($prop),
				),
			));
		}
		$return = array();
		foreach ($prop['children']['property'] as $property) {
			$name = $property['attribs']['name'];
			$return[$name] = self::convert($property);
		}
		return $return;
	}

	/**
	 * Convert xdebug/dbgp float structure to PHPDebugConsole float
	 *
	 * @param array $prop Dbgp "property" structure
	 *
	 * @return float|array
	 */
	protected static function convertFloat($prop)
	{
		if ($prop['text'] === 'INF') {
			return new Abstraction(Abstracter::TYPE_FLOAT, array(
				'typeMore' => Abstracter::TYPE_FLOAT_INF,
				'value' => Abstracter::TYPE_FLOAT_INF,
			));
		}
		if ($prop['text'] === 'NAN') {
			return new Abstraction(Abstracter::TYPE_FLOAT, array(
				'typeMore' => Abstracter::TYPE_FLOAT_NAN,
				'value' => Abstracter::TYPE_FLOAT_NAN,
			));
		}
		return (float) $prop['text'];
	}

	/**
	 * Convert xdebug/dbgp object structure to PHPDebugConsole "abstraction"
	 *
	 * @param array $prop Dbgp "property" structure
	 *
	 * @return array
	 */
	protected static function convertObject($prop)
	{
		$abs = new Abstraction(Abstracter::TYPE_OBJECT, array(
			'cfgFlags' => AbstractObject::OUTPUT_CONSTANTS | AbstractObject::OUTPUT_METHODS,
			'className' => $prop['attribs']['classname'],
			'properties' => array(),
			'methods' => array(),
		));
		$methods = array();
		$properties = array();

		/*
		<property name="closure" fullname="$values[&quot;closure&quot;]" type="object" classname="Closure" children="1" numchildren="1" page="0" pagesize="32">
		    <property name="parameter" fullname="$values[&quot;closure&quot;]-&gt;parameter" facet="public" type="array" children="1" numchildren="2" page="0" pagesize="32">
		        <property name="$foo" fullname="$values[&quot;closure&quot;]-&gt;parameter[&quot;$foo&quot;]" type="string" size="10" encoding="base64"><![CDATA[PHJlcXVpcmVkPg==]]></property>
		        <property name="$bar" fullname="$values[&quot;closure&quot;]-&gt;parameter[&quot;$bar&quot;]" type="string" size="10" encoding="base64"><![CDATA[PG9wdGlvbmFsPg==]]></property>
		    </property>
		</property>
		<property name="resource" fullname="$values[&quot;resource&quot;]" type="resource"><![CDATA[resource id='90' type='stream']]></property><property name="object" fullname="$values[&quot;object&quot;]" type="object" classname="TestObj" children="1" numchildren="4" page="0" pagesize="32"><property name="static" fullname="$values[&quot;object&quot;]::static" facet="static public" type="string" size="11" encoding="base64"><![CDATA[c3RhdGljIHByb3A=]]></property><property name="pub" fullname="$values[&quot;object&quot;]-&gt;pub" facet="public" type="string" size="5" encoding="base64"><![CDATA[aGVsbG8=]]></property><property name="pro" fullname="$values[&quot;object&quot;]-&gt;pro" facet="protected" type="string" size="10" encoding="base64"><![CDATA[cHJvdGVjdCBtZQ==]]></property><property name="private" fullname="$values[&quot;object&quot;]-&gt;private" facet="private" type="string" size="9" encoding="base64"><![CDATA[aGFuZHMgb2Zm]]></property></property></property></response>

		<property name="closure" fullname="$GLOBALS[&quot;values&quot;][&quot;closure&quot;]" type="object" classname="Closure" children="1" numchildren="1" page="0" pagesize="32">
			<property name="parameter" fullname="$GLOBALS[&quot;values&quot;][&quot;closure&quot;]-&gt;parameter" facet="public" type="array" children="1" numchildren="2"></property>
		</property>
		*/

		if (empty($prop['children']['property'])) {
			$prop['children']['property'] = array();
		}
		if ($prop['attribs']['numchildren'] !== '0' && empty($prop['children']['property'])) {
			$abs['isMaxDepth'] = true;
			$abs['attribs'] = array(
				'data-fullname' => self::getFullname($prop),
			);
		}
		foreach ($prop['children']['property'] as $property) {
			$name = $property['attribs']['name'];
			$facet = \explode(' ', $property['attribs']['facet']);
			$vis = \array_values(\array_intersect(array('public', 'protected', 'private'), $facet))[0];
			if ($abs['className'] === 'Closure' && $name === 'parameter') {
				if ($property['attribs']['numchildren'] !== '0' && empty($property['children'])) {
					$abs['isMaxDepth'] = true;
					$abs['attribs'] = array(
						'data-fullname' => self::getFullname($property),
					);
					continue;
				}
				$methods['__invoke'] = array(
					'params' => \array_map(function ($property) {
						return array(
							'name' => $property['attribs']['name'],
							'isOptional' => self::getValue($property) === '<optional>',
						);
					}, $property['children']['property']),
					'visibility' => 'public',
				);
				continue;
			}
			$properties[$name] = array(
				'value' => self::convert($property),
				'isStatic' => \in_array('static', $facet),
				'visibility' => $vis,
			);
		}
		$abs['methods'] = $methods;
		$abs['properties'] = $properties;
		return $abs;
	}

	/**
	 * Get property's full name
	 *
	 * @param array $prop Dbgp "property" structure
	 *
	 * @return array
	 */
	protected function getFullname($prop)
	{
		if (isset($prop['children']['fullname'])) {
			$fullname = $prop['children']['fullname']['text'];
			$encoding = $prop['children']['fullname']['attribs']['encoding'];
			if ($encoding === 'base64') {
				$fullname = \base64_decode($fullname);
			}
			return $fullname;
		}
		return $prop['attribs']['fullname'];
	}

	/**
	 * Get property's text value
	 *
	 * @param array $prop "property" structure
	 *
	 * @return string
	 */
	protected static function getValue($prop)
	{
		if (isset($prop['children']['value'])) {
			$value = $prop['children']['value']['text'];
			$encoding = $prop['children']['value']['attribs']['encoding'];
			if ($encoding === 'base64') {
				$value = \base64_decode($value);
			}
			return $value;
		}
		$encoding = isset($prop['attribs']['encoding'])
			? $prop['attribs']['encoding']
			: null;
		if ($encoding === 'base64') {
			return \base64_decode($prop['text']);
		}
		return $prop['text'];
	}
}
