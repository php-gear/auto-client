<?php
namespace PhpGear\AutoClient\Lib;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionParameter;
use Str;

/**
 * Generates a Javascript client-side representation of a server-side PHP REST API.
 *
 * <p>Only class methods marked as &#64;api will be exported.
 *
 * <p>The first camel-case word of API method names should be one of `get|post|put|delete` and defines the
 * corresponding HTTP verb.
 *
 * ### PHPDOC tags
 * <dl>
 * <dt>&#64;api
 * <dd>Marks the method as being exposed via HTTP.
 * <dt>&#64;alias methodName
 * <dd>Defines the client-side name of the API method, if it should be different from the server side one.
 * <dt>&#64;payload [type]
 * <dd>Declares the API method expects a POST/PUT payload, and the payload type (usually either `array` or
 * `array[]`, defaults to `array`).
 * <p>**Note:** on the controller method, the payload will always be loaded as either an associative array holding a
 * single record (for an `array` payload) or an indexed array of records (for an `array[]` payload).
 * <p>**Note:** on the controller method, a JSON payload containing an object will be loaded as an associative array,
 * not as a `StdClass` object!
 * <dt>&#64;in type name [description]
 * <dd>Defines a payload field. Should only by used fot `object` type payloads.
 * <dt>&#64;return type [description]
 * <dd>`type` should be either `array` or `array[]`, depending on whether a single record or an array of records is
 * being returned.
 * <dt>&#64;out type name [description]
 * <dd>Defines a field of the output data.
 * </dl>
 */
class AutoClient
{
  const DEFAULT_SERVICE_DESCRIPTION = 'A service that provides access to a remote API via HTTP.';

  const METHOD_DOC_TEMPLATE = <<<'JAVASCRIPT'
    /**
     * @typedef {Object} ___UCNAME___Type
___OUT_TYPE___
     */
    /**
     * @class
     * @name ___UCNAME___Promise
     * @extends {GenericPromise}
     */
    /**
     * @name ___UCNAME___Promise~then
     * @method
     * @param {function(value:___UCNAME___Type___ARRAY___):*} onFulfilled
     * @param {function(reason:*):*} [onRejected]
     * @returns ___UCNAME___Promise
     */
JAVASCRIPT;

  const METHOD_TEMPLATE   = <<<'JAVASCRIPT'
___DOC___
    this.___NAME___ = function (___ARGS___) {
      return ___TYPECAST___remote.___METHOD___ ('___URL___'___ARGS2___);
    };

JAVASCRIPT;
  const OUTPUT_FIELD      = 'out';
  const OUT_TYPE_TEMPLATE = <<<'JAVASCRIPT'
     * @property {___TYPE___} ___NAME___ ___DESCRIPTION___
JAVASCRIPT;
  /** @var string The automatically generated description for the payload parameter. */
  const PAYLOAD_DESCRIPTION     = 'The data payload to be sent with the request.';
  const PAYLOAD_FIELD           = 'in';
  const REMOTE_SERVICE_TEMPLATE = <<<'JAVASCRIPT'
/*
 * !!! DO NOT MODIFY THIS FILE !!!
 *
 * This script is automatically generated from the ___PHP_CLASS___ PHP class.
 * Your changes WILL BE LOST!
 */

(function () {
  angular.module ('___MODULE___').service ('___SERVICE___', ___CLASS___);

  /**
   * ___CLASS_DESCRIPTION___
   * @param {RemoteService} remote
   * @constructor
   * @typedef {___CLASS___} ___CLASS___
   */
  function ___CLASS___ (remote) {

___METHODS___
  }

}) ();
JAVASCRIPT;
  const TRANSLATE_TYPES         = [
    'int'     => 'number',
    'integer' => 'number',
    'double'  => 'number',
    'float'   => 'number',
    'bool'    => 'boolean',
  ];

  static function renderTemplate ($template, array $args)
  {
    $argNames  = array_map (function ($arg) { return "___{$arg}___"; }, array_keys ($args));
    $argValues = array_values ($args);
    return str_replace ($argNames, $argValues, $template);
  }

  /**
   * Scans a PHP class for exposable REST API methods and generates a parse tree containing relevant information
   * about each method.
   *
   * @param string $className The PHP class name that contains public methods exposable on a REST endpoint.
   * @return array A parse tree.
   * @throws ReflectionException
   */
  function parse ($className)
  {
    assert (!empty($className), "A class name is required");

    $baseClass = new ReflectionClass(get_parent_class ($className));
    $class     = new ReflectionClass($className);
    $methods   = $class->getMethods (ReflectionMethod::IS_PUBLIC);
    // Extract REST public methods
    $methods    = array_values (array_filter ($methods, function (ReflectionMethod $method) use ($baseClass) {
      return !$method->isStatic ()
             && $this->getDocTag ('api', $method->getDocComment ()) !== null;
      // && Str::startsWith ($method->getName (), ['get', 'post', 'put', 'delete'])
      // && !$baseClass->hasMethod ($method->getName ()); // discard inherited methods
    }));
    $apiMethods = array_map (function (ReflectionMethod $method) {
      $params  = array_map (function (ReflectionParameter $param) {
        return [
          'name'     => $param->name,
          'required' => !$param->isDefaultValueAvailable (),
          'default'  => $param->isDefaultValueAvailable () ? $param->getDefaultValue () : null,
        ];
      }, $method->getParameters ());
      $tags    = $this->parseDocTags ($method->getDocComment ());
      $altName = $tags['alias'] ?? '';
      return [
        'serverSideName' => $method->name,
        'clientSideName' => $altName ?: $method->name,
        'method'         => Util::str_extract ('/^(get|post|put|delete)/', $method->name, 1, 'get'),
        'args'           => $params,
        'doc'            => $tags,
      ];
    }, $methods);

    $classDoc = $this->parseDocTags ($class->getDocComment ());
    return [
      'description' => $classDoc['description'] ?: self::DEFAULT_SERVICE_DESCRIPTION,
      'methods'     => $apiMethods,
    ];
  }

  /**
   * @param string $apiClass    The PHP class name that contains public methods exposable on a REST endpoint.
   * @param string $endpointUrl The base URL for all published methods.
   * @param array  $parseTree   A parse tree generated by {@see parse()}.
   * @param string $module      The name of the Angular module where the service will be registered.
   * @return string
   */
  function render ($apiClass, $endpointUrl, $parseTree, $module = 'App')
  {
    $apiClass2   = str_replace ('Controller', '', $apiClass);
    $serviceName = $apiClass2 . 'Remote';
    $final       = self::renderTemplate (self::REMOTE_SERVICE_TEMPLATE, [
      'MODULE'            => $module,
      'SERVICE'           => lcfirst ($serviceName),
      'CLASS'             => ucfirst ($serviceName) . 'Service',
      'PHP_CLASS'         => $apiClass,
      'CLASS_DESCRIPTION' => $parseTree['description'],
      'METHODS'           => implode ("\n", array_map (function ($method) use ($endpointUrl) {
        $ucname        = ucfirst ($method['clientSideName']);
        $hasPayloadTag = isset($method['doc']['payload']);
        $args          = array_map (function ($arg) {
          return $arg['name'];
        }, $method['args']);
        $routeParams   = $args ? '/:' . implode ('/:', array_keys ($args)) : '';
        $payload       = $hasPayloadTag ? 'payload' : '';
        $fnArgs        = implode (', ', $args);
        $remoteParams  = $fnArgs
          ? (", [$fnArgs]" . ($payload ? ", $payload" : ''))
          : ($payload ? ", null, $payload" : '');
        $apiUrl        = Str::snake (preg_replace ('/^(get|post|put|delete)/', '', $method['serverSideName']), '-');
        $rendered      = self::renderTemplate (self::METHOD_TEMPLATE, [
          'NAME'     => $method['clientSideName'],
          'UCNAME'   => $ucname,
          'METHOD'   => $method['method'],
          'ARGS'     => implode (', ', Util::array_prune ([$payload, $fnArgs])),
          'ARGS2'    => $remoteParams,
          'URL'      => "$endpointUrl/$apiUrl$routeParams",
          'DOC'      => $this->renderMethodDoc ($method['doc'], $ucname, $typecast),
          'TYPECAST' => $typecast,
        ]);
        return self::stripEmptyLines ($rendered);
      }, $parseTree['methods'])),
    ]);
    return $final;
  }

  static private function ensureArray ($v)
  {
    return is_array ($v) ? $v : [$v];
  }

  static private function normalizeLines ($text)
  {
    return preg_replace ('/^/m', '     * ', $text);
  }

  static private function normalizeTagContent ($content)
  {
    return preg_replace ([
      '/^[ \t]*\*[ \t]*/m',         // clean consecutive lines, stripping leading * on each line
      '/\s+$/'                      // discard trailing white space
    ], ['', ''], $content);
  }

  static private function renderDocComment (array $lines)
  {
    $lines = array_map (function ($l) { return self::normalizeLines ($l); }, $lines);
    return '    /**
' . implode ("
", $lines) . '
     */';
  }

  /**
   * @param array  $doc
   * @param string $ucname
   * @return string
   */
  static private function renderOutTypeDoc (array $doc, $ucname)
  {
    $o = [];

    if (isset($doc[self::OUTPUT_FIELD])) {
      $fields = self::ensureArray ($doc[self::OUTPUT_FIELD]);
      foreach ($fields as $field) {
        list ($type, $name, $desc) = (preg_split ('/[ \t]+/', $field, 3) + [2 => '']);
        if (isset(self::TRANSLATE_TYPES[$type]))
          $type = self::TRANSLATE_TYPES[$type];
        $block = self::renderTemplate (self::OUT_TYPE_TEMPLATE, [
          'UCNAME'      => $ucname,
          'NAME'        => $name,
          'TYPE'        => $type,
          'DESCRIPTION' => $desc,
        ]);
        $o[]   = self::stripEmptyDescriptionLines ($block);
      }
    }

    return implode (PHP_EOL, $o);
  }

  static private function stripEmptyDescriptionLines ($docComment)
  {
    return preg_replace ('/^\s+\*\s*[\r\n]+/m', '', $docComment);
  }

  static private function stripEmptyLines ($docComment)
  {
    return preg_replace ('/^[\r\n]+/m', '', $docComment);
  }

  private function getDocDescription ($docBlock)
  {
    // Extract doc block description (excluding tags)
    $classDoc = Util::str_extract ('#/\*\*+\s+\*+\s*(.*?(?=@|\*/))#s', $docBlock);
    return self::normalizeTagContent ($classDoc);
  }

  private function getDocTag ($tag, $docBlock)
  {
    if (!preg_match ("#^\s*\*\s*@{$tag}[ \\t]*([\s\S]+?)(?=[^{]@|\\*+/)#m", $docBlock, $m))
      return null;
    return self::normalizeTagContent ($m[1]);
  }

  /**
   * Returns an associative array with keys corresponding to each tag on the doc comment.
   *
   * Note: multiple tags with the same name return a single key with an array of values.
   * <p>The block's description is returned on a 'description' key.
   *
   * @param string|null $docBlock
   * @return array
   */
  private function parseDocTags ($docBlock)
  {
    $o = ['description' => ''];
    if (is_null ($docBlock))
      return $o;
    $o = ['description' => $this->getDocDescription ($docBlock)];
    // Strip open /** and close */ symbols
    $docBlock = preg_replace ('#^/\*\*+\s*|\s+\*+/#', '', $docBlock);
    $spans    = preg_split ('/(?!<\{)@(?=[\w\-]+)/', $docBlock);
    if (!$spans)
      return $o;
    if ($spans[0][0] == '*') {
      $desc = array_shift ($spans);
      $o    = ['description' => self::normalizeTagContent ($desc)];
    }
    foreach ($spans as $span) {
      preg_match ('/^([\w\-]+)\s*(.*)/s', $span, $r);
      $k = $r[1];
      if ($k == 'return')
        $k = 'returns';
      $v = isset($r[2]) ? self::normalizeTagContent ($r[2]) : '';
      if (isset($o[$k])) {
        if (is_array ($o[$k]))
          $o[$k][] = $v;
        else $o[$k] = [$o[$k], $v];
      }
      else $o[$k] = $v;
    }
    return $o;
  }

  /**
   * @param array  $doc
   * @param string $ucname
   * @param string $typecast [output] A typecast expression.
   * @return string
   */
  private function renderMethodDoc (array $doc, $ucname, &$typecast)
  {
    $o = [];

    if ($doc['description']) {
      $o[] = $doc['description'];
      $o[] = '';
    }

    $hasPayloadTag = isset($doc['payload']);
    $payloadType   = $hasPayloadTag ? (ucfirst ($doc['payload']) ?: 'Object') : '';
    if ($payloadType == 'Array')
      $payloadType = 'Object';
    elseif ($payloadType == 'Array[]')
      $payloadType = 'Object[]';

    if (isset($doc['param'])) {
      $params = self::ensureArray ($doc['param']);
      if ($hasPayloadTag)
        array_unshift ($params, $payloadType . ' $payload ' . self::PAYLOAD_DESCRIPTION);
      foreach ($params as $param) {
        list ($type, $name, $desc) = preg_split ('/[ \t]+/', $param, 3);
        $name = substr ($name, 1);
        if (isset(self::TRANSLATE_TYPES[$type]))
          $type = self::TRANSLATE_TYPES[$type];
        $o[] = "@param {" . "$type} $name $desc";
      }
    }
    else if ($hasPayloadTag)
      $o[] = '@param {' . $payloadType . '} payload ' . self::PAYLOAD_DESCRIPTION;

    if (isset($doc[self::PAYLOAD_FIELD])) {
      $o[]    = '';
      $fields = self::ensureArray ($doc[self::PAYLOAD_FIELD]);
      foreach ($fields as $field) {
        list ($type, $name, $desc) = preg_split ('/[ \t]+/', $field, 3);
        if (isset(self::TRANSLATE_TYPES[$type]))
          $type = self::TRANSLATE_TYPES[$type];
        $o[] = "@param {" . "$type} payload.$name $desc";
      }
    }

    $outDoc = self::renderOutTypeDoc ($doc, $ucname);

    $typecast   = $outDoc ? "/**@type {{$ucname}Promise}*/ " : "";
    $returnType = $outDoc ? "{$ucname}Promise" : "GenericPromise";
    $returns    = $doc['returns'] ?? '';
    list ($originalType, $retDesc) = (preg_split ('/[ \t]+/', $returns, 2) + [1 => '']);
    $o[] = "@returns {{$returnType}} $retDesc";

    $methodComment = self::renderDocComment ($o);

    if ($outDoc) {
      $rendered = self::renderTemplate (self::METHOD_DOC_TEMPLATE, [
        'UCNAME'   => $ucname,
        'OUT_TYPE' => $outDoc,
        'ARRAY'    => substr ($originalType, -2) == '[]' ? '[]' : '',
      ]);
      return "$rendered
$methodComment";
    }

    return $methodComment;
  }
}
