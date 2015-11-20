<?php
/*

Copyrights for code authored by Yahoo! Inc. is licensed under the following terms:
MIT License
Copyright (c) 2013-2015 Yahoo! Inc. All Rights Reserved.
Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:
The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

Origin: https://github.com/zordius/lightncandy
*/

/**
 * file of LightnCandy Compiler
 *
 * @package    LightnCandy
 * @author     Zordius <zordius@yahoo-inc.com>
 */

namespace LightnCandy;

use \LightnCandy\Validator;
use \LightnCandy\Token;
use \LightnCandy\Expression;

/**
 * LightnCandy Compiler
 */
class Compiler extends Validator {
    public static $lastParsed;

    /**
     * Compile template into PHP code
     *
     * @param array<string,array|string|integer> $context Current context
     * @param string $template handlebars template
     *
     * @return string|null generated PHP code
     */
    public static function compileTemplate(&$context, $template) {
        array_unshift($context['parsed'], array());
        Validator::verify($context, $template);

        if (count($context['error'])) {
            return;
        }

        // Do PHP code generation.
        Parser::setDelimiter($context);

        // Handle dynamic partials
        Partial::handleDynamicPartial($context);

        $code = '';
        foreach ($context['parsed'][0] as $info) {
            if (is_array($info)) {
                $context['tokens']['current']++;
                $tmpl = static::compileToken($info, $context);
                if ($tmpl == $context['ops']['seperator']) {
                    $tmpl = '';
                } else {
                    $tmpl = "'$tmpl'";
                }
                $code .= $tmpl;
            } else {
                $code .= $info;
            }
        }

        static::$lastParsed = array_shift($context['parsed']);

        return $code;
    }

    /**
     * Compose LightnCandy render codes for include()
     *
     * @param array<string,array|string|integer> $context Current context
     * @param string $code generated PHP code
     *
     * @return string Composed PHP code
     */
    public static function composePHPRender($context, $code) {
        $flagJStrue = Expression::boolString($context['flags']['jstrue']);
        $flagJSObj = Expression::boolString($context['flags']['jsobj']);
        $flagSPVar = Expression::boolString($context['flags']['spvar']);
        $flagProp = Expression::boolString($context['flags']['prop']);
        $flagMethod = Expression::boolString($context['flags']['method']);
        $flagLambda = Expression::boolString($context['flags']['lambda']);
        $flagMustlok = Expression::boolString($context['flags']['mustlok']);
        $flagMustlam = Expression::boolString($context['flags']['mustlam']);
        $flagEcho = Expression::boolString($context['flags']['echo']);

        $libstr = Exporter::runtime($context);
        $constants = Exporter::constants($context);
        $helpers = Exporter::helpers($context);
        $bhelpers = Exporter::helpers($context, 'blockhelpers');
        $hbhelpers = Exporter::helpers($context, 'hbhelpers');
        $debug = Runtime::DEBUG_ERROR_LOG;
        $phpstart = $context['flags']['bare'] ? '' : "<?php use {$context['runtime']} as LR;\n";
        $phpend = $context['flags']['bare'] ? ';' : "\n?>";

        // Return generated PHP code string.
        return "{$phpstart}return function (\$in, \$debugopt = $debug) {
    \$cx = array(
        'flags' => array(
            'jstrue' => $flagJStrue,
            'jsobj' => $flagJSObj,
            'spvar' => $flagSPVar,
            'prop' => $flagProp,
            'method' => $flagMethod,
            'lambda' => $flagLambda,
            'mustlok' => $flagMustlok,
            'mustlam' => $flagMustlam,
            'echo' => $flagEcho,
            'debug' => \$debugopt,
        ),
        'constants' => $constants,
        'helpers' => $helpers,
        'blockhelpers' => $bhelpers,
        'hbhelpers' => $hbhelpers,
        'partials' => array({$context['partialCode']}),
        'scopes' => array(),
        'sp_vars' => array('root' => \$in),
        'runtime' => '{$context['runtime']}',
$libstr
    );
    {$context['renderex']}
    {$context['ops']['op_start']}'$code'{$context['ops']['op_end']}
}$phpend";
    }

    /**
     * Get function name for standalone or none standalone template.
     *
     * @param array<string,array|string|integer> $context Current context of compiler progress.
     * @param string $name base function name
     * @param string $tag original handlabars tag for debug
     *
     * @return string compiled Function name
     *
     * @expect 'LR::test(' when input array('flags' => array('standalone' => 0, 'debug' => 0), 'runtime' => 'Runtime'), 'test', ''
     * @expect 'LR::test2(' when input array('flags' => array('standalone' => 0, 'debug' => 0), 'runtime' => 'Runtime'), 'test2', ''
     * @expect "\$cx['funcs']['test3'](" when input array('flags' => array('standalone' => 1, 'debug' => 0), 'runtime' => 'Runtime'), 'test3', ''
     * @expect 'LR::debug(\'abc\', \'test\', ' when input array('flags' => array('standalone' => 0, 'debug' => 1), 'runtime' => 'Runtime'), 'test', 'abc'
     */
    protected static function getFuncName(&$context, $name, $tag) {
        static::addUsageCount($context, 'runtime', $name);

        if ($context['flags']['debug'] && ($name != 'miss')) {
            $dbg = "'$tag', '$name', ";
            $name = 'debug';
            static::addUsageCount($context, 'runtime', 'debug');
        } else {
            $dbg = '';
        }

        return $context['flags']['standalone'] ? "\$cx['funcs']['$name']($dbg" : "LR::$name($dbg";
    }

    /**
     * Get string presentation of variables
     *
     * @param array<array> $vn variable name array.
     * @param array<string,array|string|integer> $context current compile context
     *
     * @return array<string|array> variable names
     *
     * @expect array('array(array($in),array())', array('this')) when input array(null), array('flags'=>array('spvar'=>true))
     * @expect array('array(array($in,$in),array())', array('this', 'this')) when input array(null, null), array('flags'=>array('spvar'=>true))
     * @expect array('array(array(),array(\'a\'=>$in))', array('this')) when input array('a' => null), array('flags'=>array('spvar'=>true))
     */
    protected static function getVariableNames($vn, &$context) {
        $vars = array(array(), array());
        $exps = array();
        foreach ($vn as $i => $v) {
            $V = static::getVariableNameOrSubExpression($v, $context);
            if (is_string($i)) {
                $vars[1][] = "'$i'=>{$V[0]}";
            } else {
                $vars[0][] = $V[0];
            }
            $exps[] = $V[1];
        }
        return array('array(array(' . implode(',', $vars[0]) . '),array(' . implode(',', $vars[1]) . '))', $exps);
    }

    /**
     * Get string presentation of a sub expression
     *
     * @param array<boolean|integer|string|array> $vars parsed arguments list
     * @param array<string,array|string|integer> $context current compile context
     *
     * @return array<string> code representing passed expression
     */
    public static function compileSubExpression($vars, &$context) {
        $origSeperator = $context['ops']['seperator'];
        $context['ops']['seperator'] = '';

        $ret = static::customHelper($context, $vars, true);

        if (($ret === null) && $context['flags']['lambda']) {
            $ret = static::compileVariable($context, $vars, true);
        }

        $context['ops']['seperator'] = $origSeperator;

        return array($ret ? $ret : '', 'FIXME: $subExpression');
    }

    /**
     * Get string presentation of a subexpression or a variable
     *
     * @param array<array|string|integer> $var variable parsed path
     * @param array<array|string|integer> $context current compile context
     *
     * @return array<string> variable names
     */
    protected static function getVariableNameOrSubExpression($var, &$context) {
        return Parser::isSubexp($var) ? static::compileSubExpression($var[1], $context) : static::getVariableName($var, $context);
    }

    /**
     * Get string presentation of a variable
     *
     * @param array<array|string|integer> $var variable parsed path
     * @param array<array|string|integer> $context current compile context
     *
     * @return array<string> variable names
     *
     * @expect array('$in', 'this') when input array(null), array('flags'=>array('spvar'=>true,'debug'=>0))
     * @expect array('((isset($in[\'true\']) && is_array($in)) ? $in[\'true\'] : null)', '[true]') when input array('true'), array('flags'=>array('spvar'=>true,'debug'=>0,'prop'=>0,'method'=>0,'mustlok'=>0,'mustlam'=>0, 'lambda'=>0))
     * @expect array('((isset($in[\'false\']) && is_array($in)) ? $in[\'false\'] : null)', '[false]') when input array('false'), array('flags'=>array('spvar'=>true,'debug'=>0,'prop'=>0,'method'=>0,'mustlok'=>0,'mustlam'=>0, 'lambda'=>0))
     * @expect array('true', 'true') when input array(0, 'true'), array('flags'=>array('spvar'=>true,'debug'=>0))
     * @expect array('false', 'false') when input array(0, 'false'), array('flags'=>array('spvar'=>true,'debug'=>0))
     * @expect array('((isset($in[\'2\']) && is_array($in)) ? $in[\'2\'] : null)', '[2]') when input array('2'), array('flags'=>array('spvar'=>true,'debug'=>0,'prop'=>0,'method'=>0,'mustlok'=>0,'mustlam'=>0, 'lambda'=>0))
     * @expect array('2', '2') when input array(0, '2'), array('flags'=>array('spvar'=>true,'debug'=>0,'prop'=>0,'method'=>0))
     * @expect array('((isset($in[\'@index\']) && is_array($in)) ? $in[\'@index\'] : null)', '[@index]') when input array('@index'), array('flags'=>array('spvar'=>false,'debug'=>0,'prop'=>0,'method'=>0,'mustlok'=>0,'mustlam'=>0, 'lambda'=>0))
     * @expect array("((isset(\$cx['sp_vars']['index']) && is_array(\$cx['sp_vars'])) ? \$cx['sp_vars']['index'] : null)", '@[index]') when input array('@index'), array('flags'=>array('spvar'=>true,'debug'=>0,'prop'=>0,'method'=>0,'mustlok'=>0,'mustlam'=>0, 'lambda'=>0))
     * @expect array("((isset(\$cx['sp_vars']['key']) && is_array(\$cx['sp_vars'])) ? \$cx['sp_vars']['key'] : null)", '@[key]') when input array('@key'), array('flags'=>array('spvar'=>true,'debug'=>0,'prop'=>0,'method'=>0,'mustlok'=>0,'mustlam'=>0, 'lambda'=>0))
     * @expect array("((isset(\$cx['sp_vars']['first']) && is_array(\$cx['sp_vars'])) ? \$cx['sp_vars']['first'] : null)", '@[first]') when input array('@first'), array('flags'=>array('spvar'=>true,'debug'=>0,'prop'=>0,'method'=>0,'mustlok'=>0,'mustlam'=>0, 'lambda'=>0))
     * @expect array("((isset(\$cx['sp_vars']['last']) && is_array(\$cx['sp_vars'])) ? \$cx['sp_vars']['last'] : null)", '@[last]') when input array('@last'), array('flags'=>array('spvar'=>true,'debug'=>0,'prop'=>0,'method'=>0,'mustlok'=>0,'mustlam'=>0, 'lambda'=>0))
     * @expect array('((isset($in[\'"a"\']) && is_array($in)) ? $in[\'"a"\'] : null)', '["a"]') when input array('"a"'), array('flags'=>array('spvar'=>true,'debug'=>0,'prop'=>0,'method'=>0,'mustlok'=>0,'mustlam'=>0, 'lambda'=>0))
     * @expect array('"a"', '"a"') when input array(0, '"a"'), array('flags'=>array('spvar'=>true,'debug'=>0))
     * @expect array('((isset($in[\'a\']) && is_array($in)) ? $in[\'a\'] : null)', '[a]') when input array('a'), array('flags'=>array('spvar'=>true,'debug'=>0,'prop'=>0,'method'=>0,'mustlok'=>0,'mustlam'=>0, 'lambda'=>0))
     * @expect array('((isset($cx[\'scopes\'][count($cx[\'scopes\'])-1][\'a\']) && is_array($cx[\'scopes\'][count($cx[\'scopes\'])-1])) ? $cx[\'scopes\'][count($cx[\'scopes\'])-1][\'a\'] : null)', '../[a]') when input array(1,'a'), array('flags'=>array('spvar'=>true,'debug'=>0,'prop'=>0,'method'=>0,'mustlok'=>0,'mustlam'=>0, 'lambda'=>0))
     * @expect array('((isset($cx[\'scopes\'][count($cx[\'scopes\'])-3][\'a\']) && is_array($cx[\'scopes\'][count($cx[\'scopes\'])-3])) ? $cx[\'scopes\'][count($cx[\'scopes\'])-3][\'a\'] : null)', '../../../[a]') when input array(3,'a'), array('flags'=>array('spvar'=>true,'debug'=>0,'prop'=>0,'method'=>0,'mustlok'=>0,'mustlam'=>0, 'lambda'=>0))
     * @expect array('((isset($in[\'id\']) && is_array($in)) ? $in[\'id\'] : null)', 'this.[id]') when input array(null, 'id'), array('flags'=>array('spvar'=>true,'debug'=>0,'prop'=>0,'method'=>0,'mustlok'=>0,'mustlam'=>0, 'lambda'=>0))
     * @expect array('LR::v($cx, $in, array(\'id\'))', 'this.[id]') when input array(null, 'id'), array('flags'=>array('prop'=>true,'spvar'=>true,'debug'=>0,'method'=>0,'mustlok'=>0,'mustlam'=>0, 'lambda'=>0,'standalone'=>0), 'runtime' => 'Runtime')
     */
    protected static function getVariableName($var, &$context) {
        if (isset($var[0]) && ($var[0] === 0)) {
            return array($var[1], preg_replace('/\'(.*)\'/', '$1', $var[1]));
        }

        list($levels, $spvar , $var) = Expression::analyze($context, $var);
        $exp = Expression::toString($levels, $spvar, $var);
        $base = $spvar ? "\$cx['sp_vars']" : '$in';

        // change base when trace to parent
        if ($levels > 0) {
            if ($spvar) {
                $base .= str_repeat("['_parent']", $levels);
            } else {
                $base = "\$cx['scopes'][count(\$cx['scopes'])-$levels]";
            }
        }

        if ((count($var) == 0) || (is_null($var[0]) && (count($var) == 1))) {
            return array($base, $exp);
        }

        if (is_null($var[0])) {
            array_shift($var);
        }

        // To support recursive context lookup, instance properties + methods and lambdas
        // the only way is using slower rendering time variable resolver.
        if ($context['flags']['prop'] || $context['flags']['method'] || $context['flags']['mustlok'] || $context['flags']['mustlam'] || $context['flags']['lambda']) {
            return array(static::getFuncName($context, 'v', $exp) . "\$cx, $base, array(" . implode(',', array_map(function ($V) {
                return "'$V'";
            }, $var)) . '))', $exp);
        }

        $n = Expression::arrayString($var);
        array_pop($var);
        $p = count($var) ? Expression::arrayString($var) : '';

        return array("((isset($base$n) && is_array($base$p)) ? $base$n : " . ($context['flags']['debug'] ? (static::getFuncName($context, 'miss', '') . "\$cx, '$exp')") : 'null' ) . ')', $exp);
    }

    /**
     * Return compiled PHP code for a handlebars token
     *
     * @param array<string,array|boolean> $info parsed information
     * @param array<string,array|string|integer> $context current compile context
     *
     * @return string Return compiled code segment for the token
     */
    protected static function compileToken($info, &$context) {
        list($raw, $vars, $token, $indent) = $info;

        $context['tokens']['partialind'] = $indent;
        $context['currentToken'] = $token;

        // Do not touch the tag, keep it as is.
        if ($raw === -1) {
            return ".'" . Token::toString($token) . "'.";
        }

        if ($ret = static::operator($token[Token::POS_OP], $context, $vars)) {
            return $ret;
        }

        if (isset($vars[0][0])) {
            if ($ret = static::customHelper($context, $vars, $raw)) {
                return $ret;
            }
            if ($vars[0][0] === 'else') {
                return static::doElse($context);
            }
            if ($vars[0][0] === 'lookup') {
                return static::lookup($context);
            }
        }

        return static::compileVariable($context, $vars, $raw);
    }

    /**
     * handle partial
     *
     * @param array<string,array|string|integer> $context current compile context
     * @param array<boolean|integer|string|array> $vars parsed arguments list
     *
     * @return string Return compiled code segment for the partial
     */
    public static function partial(&$context, $vars) {
        // mustache spec: ignore missing partial
        if (($context['usedFeature']['dynpartial'] === 0) && !isset($context['usedPartial'][$vars[0][0]])) {
            return $context['ops']['seperator'];
        }
        $p = array_shift($vars);
        if (!isset($vars[0])) {
            $vars[0] = array();
        }
        $v = static::getVariableNames($vars, $context);
        $tag = ">$p[0] " .implode(' ', $v[1]);
        if ($context['flags']['runpart']) {
            if (Parser::isSubexp($p)) {
                list($p) = static::compileSubExpression($p[1], $context);
            } else {
                $p = "'$p[0]'";
            }
            $sp = $context['tokens']['partialind'] ? ", '{$context['tokens']['partialind']}'" : '';
            return $context['ops']['seperator'] . static::getFuncName($context, 'p', $tag) . "\$cx, $p, $v[0]$sp){$context['ops']['seperator']}";
        }
        return "{$context['ops']['seperator']}'" . Partial::compileStatic($context, $p[0]) . "'{$context['ops']['seperator']}";
    }

    /**
     * Return compiled PHP code for a handlebars inverted section begin token
     *
     * @param array<string,array|string|integer> $context current compile context
     * @param array<boolean|integer|string|array> $vars parsed arguments list
     *
     * @return string Return compiled code segment for the token
     */
    protected static function invertedSection(&$context, $vars) {
        $v = static::getVariableName($vars[0], $context);
        return "{$context['ops']['cnd_start']}(" . static::getFuncName($context, 'isec', '^' . $v[1]) . "\$cx, {$v[0]})){$context['ops']['cnd_then']}";
    }

    /**
     * Return compiled PHP code for a handlebars block custom helper begin token
     *
     * @param array<string,array|string|integer> $context current compile context
     * @param array<boolean|integer|string|array> $vars parsed arguments list
     * @param boolean $inverted the logic will be inverted
     *
     * @return string Return compiled code segment for the token
     */
    protected static function blockCustomHelper(&$context, $vars, $inverted = false) {
        $notHBCH = !isset($context['hbhelpers'][$vars[0][0]]);

        $ch = array_shift($vars);
        $inverted = $inverted ? 'true' : 'false';

        static::addUsageCount($context, $notHBCH ? 'blockhelpers' : 'hbhelpers', $ch[0]);
        $v = static::getVariableNames($vars, $context);
        return $context['ops']['seperator'] . static::getFuncName($context, $notHBCH ? 'bch' : 'hbch', ($inverted ? '^' : '#') . implode(' ', $v[1])) . "\$cx, '$ch[0]', {$v[0]}, \$in, $inverted, function(\$cx, \$in) {{$context['ops']['f_start']}";
    }

    /**
     * Return compiled PHP code for a handlebars block end token
     *
     * @param array<string,array|string|integer> $context current compile context
     * @param array<boolean|integer|string|array> $vars parsed arguments list
     *
     * @return string Return compiled code segment for the token
     */
    protected static function blockEnd(&$context, $vars) {
        $pop = $context['stack'][count($context['stack']) - 1];
        if ($pop === ':') {
            array_pop($context['stack']);
            return $context['usedFeature']['parent'] ? "{$context['ops']['f_end']}}){$context['ops']['seperator']}" : "{$context['ops']['cnd_end']}";
        }
        switch ($context['currentToken'][Token::POS_INNERTAG]) {
            case 'if':
            case 'unless':
                if (!$context['flags']['nohbh']) {
                    return $context['usedFeature']['parent'] ? "{$context['ops']['f_end']}}){$context['ops']['seperator']}" : "{$context['ops']['cnd_else']}''{$context['ops']['cnd_end']}";
                }
                break;
            case 'with':
                if (!$context['flags']['nohbh']) {
                    return "{$context['ops']['f_end']}}){$context['ops']['seperator']}";
                }
        }

        $v = static::getVariableName($vars[0], $context);
        switch($pop) {
            case '#':
                return "{$context['ops']['f_end']}}){$context['ops']['seperator']}";
            case '^':
                return "{$context['ops']['cnd_else']}''{$context['ops']['cnd_end']}";
        }
    }

    /**
     * Return compiled PHP code for a handlebars block begin token
     *
     * @param array<string,array|string|integer> $context current compile context
     * @param array<boolean|integer|string|array> $vars parsed arguments list
     *
     * @return string Return compiled code segment for the token
     */
    protected static function blockBegin(&$context, $vars) {
        $v = isset($vars[1]) ? static::getVariableNameOrSubExpression($vars[1], $context) : array(null, array());
        if (!$context['flags']['nohbh']) {
            switch (isset($vars[0][0]) ? $vars[0][0] : null) {
                case 'if':
                    $includeZero = (isset($vars['includeZero'][1]) && $vars['includeZero'][1]) ? 'true' : 'false';
                    return $context['usedFeature']['parent']
                        ? $context['ops']['seperator'] . static::getFuncName($context, 'ifv', 'if ' . $v[1]) . "\$cx, {$v[0]}, {$includeZero}, \$in, function(\$cx, \$in) {{$context['ops']['f_start']}"
                        : "{$context['ops']['cnd_start']}(" . static::getFuncName($context, 'ifvar', $v[1]) . "\$cx, {$v[0]}, {$includeZero})){$context['ops']['cnd_then']}";
                case 'unless':
                    return $context['usedFeature']['parent']
                        ? $context['ops']['seperator'] . static::getFuncName($context, 'unl', 'unless ' . $v[1]) . "\$cx, {$v[0]}, false, \$in, function(\$cx, \$in) {{$context['ops']['f_start']}"
                        : "{$context['ops']['cnd_start']}(!" . static::getFuncName($context, 'ifvar', $v[1]) . "\$cx, {$v[0]}, false)){$context['ops']['cnd_then']}";
                case 'each':
                    return static::section($context, $vars, true);
                case 'with':
                    if ($r = static::with($context, $vars)) {
                        return $r;
                    }
            }
        }

        return static::section($context, $vars);
    }

    /**
     * compile {{#foo}} token
     *
     * @param array<string,array|string|integer> $context current compile context
     * @param array<boolean|integer|string|array> $vars parsed arguments list
     * @param boolean $isEach the section is #each
     *
     * @return string|null Return compiled code segment for the token
     */
    protected static function section(&$context, $vars, $isEach = false) {
        if ($isEach) {
            array_shift($vars);
            if (!isset($vars[0])) {
                $vars[0] = array(null);
            }
        }
        $v = static::getVariableNameOrSubExpression($vars[0], $context);
        $each = $isEach ? 'true' : 'false';
        return $context['ops']['seperator'] . static::getFuncName($context, 'sec', ($isEach ? 'each ' : '') . $v[1]) . "\$cx, {$v[0]}, \$in, $each, function(\$cx, \$in) {{$context['ops']['f_start']}";
    }

    /**
     * compile {{with}} token
     *
     * @param array<string,array|string|integer> $context current compile context
     * @param array<boolean|integer|string|array> $vars parsed arguments list
     *
     * @return string|null Return compiled code segment for the token
     */
    protected static function with(&$context, $vars) {
        $v = isset($vars[1]) ? static::getVariableNameOrSubExpression($vars[1], $context) : array(null, array());
        return $context['ops']['seperator'] . static::getFuncName($context, 'wi', 'with ' . $v[1]) . "\$cx, {$v[0]}, \$in, function(\$cx, \$in) {{$context['ops']['f_start']}";
    }

    /**
     * Return compiled PHP code for a handlebars custom helper token
     *
     * @param array<string,array|string|integer> $context current compile context
     * @param array<boolean|integer|string|array> $vars parsed arguments list
     * @param boolean $raw is this {{{ token or not
     *
     * @return string|null Return compiled code segment for the token when the token is custom helper
     */
    protected static function customHelper(&$context, $vars, $raw) {
        $notHH = !isset($context['hbhelpers'][$vars[0][0]]);
        if (!isset($context['helpers'][$vars[0][0]]) && $notHH) {
            return;
        }

        $fn = $raw ? 'raw' : $context['ops']['enc'];
        $ch = array_shift($vars);
        $v = static::getVariableNames($vars, $context);
        static::addUsageCount($context, $notHH ? 'helpers' : 'hbhelpers', $ch[0]);
        return $context['ops']['seperator'] . static::getFuncName($context, $notHH ? 'ch' : 'hbch', "$ch[0] " . implode(' ', $v[1])) . "\$cx, '$ch[0]', {$v[0]}, '$fn'" . ($notHH ? '' : ', $in') . "){$context['ops']['seperator']}";
    }

    /**
     * Return compiled PHP code for a handlebars else token
     *
     * @param array<string,array|string|integer> $context current compile context
     *
     * @return string Return compiled code segment for the token when the token is else
     */
    protected static function doElse(&$context) {
        switch ($context['stack'][count($context['stack']) - 2]) {
            case '[if]':
            case '[unless]':
                $context['stack'][] = ':';
                return $context['usedFeature']['parent'] ? "{$context['ops']['f_end']}}, function(\$cx, \$in) {{$context['ops']['f_start']}" : "{$context['ops']['cnd_else']}";
            default:
                return "{$context['ops']['f_end']}}, function(\$cx, \$in) {{$context['ops']['f_start']}";
        }
    }

    /**
     * Return compiled PHP code for a handlebars variable token
     *
     * @param array<string,array|string|integer> $context current compile context
     * @param array<boolean|integer|string|array> $vars parsed arguments list
     * @param boolean $raw is this {{{ token or not
     *
     * @return string Return compiled code segment for the token
     */
    protected static function compileVariable(&$context, &$vars, $raw) {
        $v = static::getVariableName($vars[0], $context);
        if ($context['flags']['jsobj'] || $context['flags']['jstrue'] || $context['flags']['debug']) {
            return $context['ops']['seperator'] . static::getFuncName($context, $raw ? 'raw' : $context['ops']['enc'], $v[1]) . "\$cx, {$v[0]}){$context['ops']['seperator']}";
        } else {
            return $raw ? "{$context['ops']['seperator']}$v[0]{$context['ops']['seperator']}" : "{$context['ops']['seperator']}htmlentities((string){$v[0]}, ENT_QUOTES, 'UTF-8'){$context['ops']['seperator']}";
        }
    }

    /**
     * Add usage count to context
     *
     * @param array<string,array|string|integer> $context current context
     * @param string $category ctegory name, can be one of: 'var', 'helpers', 'blockhelpers'
     * @param string $name used name
     * @param integer $count increment
     *
     * @expect 1 when input array('usedCount' => array('test' => array())), 'test', 'testname'
     * @expect 3 when input array('usedCount' => array('test' => array('testname' => 2))), 'test', 'testname'
     * @expect 5 when input array('usedCount' => array('test' => array('testname' => 2))), 'test', 'testname', 3
     */
    protected static function addUsageCount(&$context, $category, $name, $count = 1) {
        if (!isset($context['usedCount'][$category][$name])) {
            $context['usedCount'][$category][$name] = 0;
        }
        return ($context['usedCount'][$category][$name] += $count);
    }
}

