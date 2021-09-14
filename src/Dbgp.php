<?php

namespace bdk\XdebugWamp;

/**
 *
 */
class Dbgp extends DbgpBase
{
    protected $requestIdIncl = true;
    protected $requestIdPrefix = 'wamp';
}
