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
 *
 * <dt>&#64;alias methodName
 * <dd>Defines the client-side name of the API method, if it should be different from the server side one.
 *
 * <dt>&#64;param type name [description]
 * <dd>Declares a route parameter that is passed as an argument to the method.
 *
 * <dt>&#64;query type name [description]
 * <dd>Declares a query string parameter that is passed to the server-side API on the URL.
 * <p>**Note:** Query parameters are encoded in the URL-encoding format (`key=value&key=value...`).
 * <p>All query parameters must be merged by the caller into a single object as key->value pairs; that object must be
 * passed to the generated client-side function as the **last** argument on the function's argument list.
 *
 * <dt>&#64;payload [type]
 * <dd>Declares the API method expects a POST/PUT payload, and the payload type (usually either `array` or
 * `array[]`, defaults to `array`).
 * <p>The payload must be passed to the generated client-side function as the **first** argument on the function's
 * argument list.
 * <p>**Note:** on the controller method, the payload will always be loaded as either an associative array holding a
 * single record (for an `array` payload) or an indexed array of records (for an `array[]` payload).
 * <p>**Note:** on the controller method, a JSON payload containing an object will be loaded as an associative array,
 * not as a `StdClass` object!
 *
 * <dt>&#64;in type name [description]
 * <dd>Defines a payload field. Should only by used fot `object` type payloads.
 *
 * <dt>&#64;return type [description]
 * <dd>`type` should be either `array` or `array[]`, depending on whether a single record or an array of records is
 * being returned.
 *
 * <dt>&#64;out type name [description]
 * <dd>Defines a field of the output data.
 * </dl>
 */
class AutoClient
{
  const DEFAULT_SERVICE_DESCRIPTION = 'A service that provides access to a remote API via HTTP.';
  const INPUT_TAG                   = 'in';
  const METHOD_DOC_TEMPLATE         = <<<'JAVASCRIPT'
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
  const METHOD_TEMPLATE             = <<<'JAVASCRIPT'
___DOC___
    this.___NAME___ = function (___ARGS___) {
      return ___TYPECAST___remote.___METHOD___ ('___URL___'___ARGS2___);
    };

JAVASCRIPT;
  const OUTPUT_TAG                  = 'out';
  const OUT_TYPE_TEMPLATE           = <<<'JAVASCRIPT'
     * @property {___TYPE___} ___NAME___ ___DESCRIPTION___
JAVASCRIPT;
  /** @var string The automatically generated description for the payload parameter. */
  const PAYLOAD_DESCRIPTION     = 'The data payload to be sent with the request';
  const PAYLOAD_PARAM           = 'payload';
  const PAYLOAD_TAG             = 'payload';
  const QUERY_PARAM             = 'queryParams';
  const QUERY_PARAM_DESCRIPTION = 'URL query string parameters';
  const QUERY_TAG               = 'query';
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
   * @name ___CLASS___
   * @class
   */
  /**
   * @constructor
   * @param {RemoteService} remote
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
      $tags    = $this->parseDocTags ($method->getDocComment ());
      $altName = $tags['alias'] ?? '';

      $paramsDesc = $paramsType = [];
      foreach (self::ensureArray ($tags['param'] ?? []) as $v) {
        list ($type, $name, $desc) = preg_split ('/[ \t]+/', $v, 3) + [2 => ''];
        $name              = substr ($name, 1); // remove leading $
        $paramsType[$name] = $type;
        $paramsDesc[$name] = $desc;
      }

      $params = array_map (function (ReflectionParameter $param) use ($paramsDesc, $paramsType) {
        if ($param->isDefaultValueAvailable ()) {
          $default = $param->getDefaultValue ();
          // Note: only empty arrays are supported as default values of array type.
          $default = isset($default)
            ? preg_replace ('/array\s+.*/s', '[]', var_export ($default, true))
            : 'null';
        }
        else $default = '';
        return [
          'name'        => $param->name,
          'type'        => $paramsType[$param->name] ?? '',
          'required'    => !$param->isDefaultValueAvailable (),
          'default'     => $default, // textual representation of the default value, '' for no default
          'description' => $paramsDesc[$param->name] ?? '',
        ];
      }, $method->getParameters ());

      $queryParams = array_map (function ($param) {
        list ($type, $name, $desc) = preg_split ('/[ \t]+/', $param, 3) + [2 => ''];
        return [
          'name'        => $name,
          'type'        => $type,
          'description' => $desc,
        ];
      }, self::ensureArray ($tags[self::QUERY_TAG] ?? []));

      return [
        'serverSideName' => $method->name,
        'clientSideName' => $altName ?: $method->name,
        'method'         => Util::str_extract ('/^(get|post|put|delete)/', $method->name, 1, 'get'),
        'args'           => $params,
        'queryArgs'      => $queryParams,
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
        $hasPayloadTag = isset($method['doc'][self::PAYLOAD_TAG]);
        $args          = array_map (function ($arg) {
          return $arg['name'];
        }, $method['args']);
        $routeParams   = $args ? '/:' . implode ('/:', array_keys ($args)) : '';
        $payload       = $hasPayloadTag ? self::PAYLOAD_PARAM : '';

        $queryArgs       = $method['queryArgs'];
        $queryParam      = $queryArgs ? self::QUERY_PARAM : '';
        $remoteQueryArgs = [];
        if ($queryArgs) {
          // Generate URL-encoded key=value pairs
          $c          = count ($args);
          $queryPairs = array_map (function ($param) use (&$c, &$remoteQueryArgs) {
            $remoteQueryArgs[] = self::QUERY_PARAM . '.' . $param['name'];
            return urlencode ($param['name']) . '=:' . ($c++);
          }, $queryArgs);
          // Generate the query string
          $routeParams .= '?' . implode ('&', $queryPairs);
        }

        $fnArgs       = implode (', ', array_merge ($args, $remoteQueryArgs));
        $remoteParams = $fnArgs
          ? (", [$fnArgs]" . ($payload ? ", $payload" : ''))
          : ($payload ? ", null, $payload" : '');
        $apiUrl       = Str::snake (preg_replace ('/^(get|post|put|delete)/', '', $method['serverSideName']), '-');

        $rendered = self::renderTemplate (self::METHOD_TEMPLATE, [
          'NAME'     => $method['clientSideName'],
          'UCNAME'   => $ucname,
          'METHOD'   => $method['method'],
          'ARGS'     => implode (', ', Util::array_prune ([$payload, implode (', ', $args), $queryParam])),
          'ARGS2'    => $remoteParams,
          'URL'      => "$endpointUrl/$apiUrl$routeParams",
          'DOC'      => $this->renderMethodDoc ($method, $ucname, $typecast, $queryArgs),
          'TYPECAST' => $typecast,
        ]);
        return self::stripEmptyLines ($rendered);
      }, $parseTree['methods'])),
    ]);
    return $final;
  }

  static private function ensureArray ($v)
  {
    return is_null ($v) ? [] : (is_array ($v) ? $v : [$v]);
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

    if (isset($doc[self::OUTPUT_TAG])) {
      $fields = self::ensureArray ($doc[self::OUTPUT_TAG]);
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
   * @param array  $method    Parsed information about the method.
   * @param string $ucname
   * @param string $typecast  [output] A typecast expression.
   * @param array  $queryArgs Query arguments metadata.
   * @return string
   */
  private function renderMethodDoc (array $method, $ucname, &$typecast, array $queryArgs)
  {
    $doc = $method['doc'];
    $o   = [];

    if ($doc['description']) {
      $o[] = $doc['description'];
      $o[] = '';
    }

    $hasPayloadTag = isset($doc[self::PAYLOAD_TAG]);
    $payloadType   = $hasPayloadTag ? (ucfirst ($doc[self::PAYLOAD_TAG]) ?: 'Object') : '';
    if ($payloadType == 'Array')
      $payloadType = 'Object';
    elseif ($payloadType == 'Array[]')
      $payloadType = 'Object[]';

    if ($params = $method['args']) {
      if ($hasPayloadTag)
        array_unshift ($params, [
          'name'        => self::PAYLOAD_PARAM,
          'type'        => $payloadType,
          'description' => self::PAYLOAD_DESCRIPTION,
          'required'    => true,
        ]);
      foreach ($params as $param) {
        $name = $param['name'];
        $type = $param['type'];
        $desc = $param['description'];
        if (!$param['required'])
          $name = isset($param['default']) ? "[$name={$param['default']}]" : "[$name]";
        if (isset(self::TRANSLATE_TYPES[$type]))
          $type = self::TRANSLATE_TYPES[$type];
        $o[] = "@param {" . "$type} $name $desc";
      }
    }
    else if ($hasPayloadTag)
      $o[] = '@param {' . $payloadType . '} payload ' . self::PAYLOAD_DESCRIPTION;

    if (isset($doc[self::INPUT_TAG])) {
      $o[]    = '';
      $fields = self::ensureArray ($doc[self::INPUT_TAG]);
      foreach ($fields as $field) {
        list ($type, $name, $desc) = preg_split ('/[ \t]+/', $field, 3) + ['', '', ''];
        if (isset(self::TRANSLATE_TYPES[$type]))
          $type = self::TRANSLATE_TYPES[$type];
        $o[] = "@param {" . "$type} " . self::PAYLOAD_PARAM . ".$name $desc";
      }
    }

    if ($queryArgs) {
      $o[] = "@param {object} " . self::QUERY_PARAM . " " . self::QUERY_PARAM_DESCRIPTION;
      foreach ($queryArgs as $arg) {
        $o[] = "@param {" . "{$arg['type']}} " . self::QUERY_PARAM . ".{$arg['name']} {$arg['description']}";
      }
    }

    $outDoc = self::renderOutTypeDoc ($doc, $ucname);

    $typecast   = $outDoc ? "/**@type {{$ucname}Promise}*/ " : "";
    $returnType = $outDoc ? "{$ucname}Promise" : "GenericPromise";
    $returns    = $doc['returns'] ?? '';
    list ($originalType, $retDesc) = (preg_split ('/[ \t]+/', $returns, 2) + ['', '']);
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
