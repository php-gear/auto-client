<?php
namespace PhpGear\AutoClient\Lib;

class Util
{
  /**
   * Extracts a sub-string of a string by regular expression.
   *
   * @param string $regexp
   * @param string $str
   * @param int    $captureIdx [optional] Index of the capture group that will be returned.
   * @param mixed  $default    [optional]
   * @return null
   */
  static function str_extract ($regexp, $str, $captureIdx = 1, $default = null)
  {
    return preg_match ($regexp, $str, $m) ? $m[$captureIdx] : $default;
  }

  /**
   * Removes empty elements from an array.
   *
   * An empty value is either null, an empty string or an empty array.
   *
   * @param array $a
   * @return array
   */
  static function array_prune (array $a)
  {
    $o = [];
    foreach ($a as $k => $v)
      if (isset ($v) && $v !== '' && $v !== [])
        $o[$k] = $v;
    return $o;
  }

}
