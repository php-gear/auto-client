<?php
namespace PhpGear\AutoClient\Laravel;

use PhpGear\AutoClient\Lib\AutoClient;
use Illuminate\Console\Command;
use Config;
use Log;

class AutoClientCommand extends Command
{
  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'Generates Javascript client-side APIs from REST PHP APIs';
  /**
   * The console command name.
   *
   * @var string
   */
  protected $name = 'autoclient:generate';
  /**
   * showFooter
   *
   * @var bool
   */
  protected $showFooter = false;

  /**
   * @throws \Exception
   */
  public function fire ()
  {
    $this->line ("Exporting controllers:");
    foreach (Config::get('app.autoclient.APIs', []) as $url => $info) {
      list ($class, $targetDir, $module) = $info;
      $this->export ($class, $url, $targetDir, $module);
    }
  }

  /**
   * Aborts execution with an error message.
   *
   * @param string $msg Error message.
   *
   * @throws \Exception
   */
  protected function abort ($msg = '')
  {
    if ($msg)
      Log::error ($msg);
    exit (1);
  }

  /**
   * @param string $class
   * @param string $url
   * @param string $targetDir
   * @param string $module
   * @throws \ReflectionException
   * @throws \Exception
   */
  private function export ($class, $url, $targetDir, $module)
  {
    $path      = sprintf ('%s/%s.js', $base = base_path ($targetDir), str_replace ('Controller', '', lcfirst ($class)));
    if (!is_dir ($base))
      $this->abort ("Directory $base does not exist");

    $builder   = new AutoClient;
    $tree      = $builder->parse ($class);
    $generated = $builder->render ($class, $url, $tree, $module);

    if (!file_put_contents ($path, $generated))
      $this->abort ("Could not write to $path");

    $this->line ("  <comment>$class</comment>\t- <info>exported</info>");
  }

}
