<?php
/**
 * Whoops - php errors for cool kids
 * @author Filipe Dobreira <http://github.com/filp>
 */

namespace Extensions\Whoops\Handler;

use \Whoops\Handler\Handler as Handler;
use \Whoops\Util\Misc;
use \Whoops\Util\TemplateHelper;
use \Whoops\Exception\Formatter;
use \InvalidArgumentException;
use \RuntimeException;

class AuroraHandler extends Handler{#

	public static $aurora_handler = [];
	public static $callback;
	/**
	 * Search paths to be scanned for resources, in the reverse
	 * order they're declared.
	 *
	 * @var array
	 */
	private $searchPaths = [];

	/**
	 * Fast lookup cache for known resource locations.
	 *
	 * @var array
	 */
	private $resourceCache = [];

	/**
	 * The name of the custom css file.
	 *
	 * @var string
	 */
	private $customCss = null;

	/**
	 * @var array[]
	 */
	private static $extraTables = [];

	/**
	 * @var bool
	 */
	private $handleUnconditionally = false;

	/**
	 * @var string
	 */
	private static $pageTitle = "Whoops! There was an error.";

	/**
	 * A string identifier for a known IDE/text editor, or a closure
	 * that resolves a string that can be used to open a given file
	 * in an editor. If the string contains the special substrings
	 * %file or %line, they will be replaced with the correct data.
	 *
	 * @example
	 *  "txmt://open?url=%file&line=%line"
	 * @var mixed $editor
	 */
	protected $editor;

	/**
	 * A list of known editor strings
	 * @var array
	 */
	protected $editors = array(
		"sublime"  => "subl://open?url=file://%file&line=%line",
		"textmate" => "txmt://open?url=file://%file&line=%line",
		"emacs"    => "emacs://open?url=file://%file&line=%line",
		"macvim"   => "mvim://open/?url=file://%file&line=%line"
	);

	/**
	 * Constructor.
	 */
	public function __construct(/*\Katzgrau\KLogger\Logger $Logger*/)	{
		if (ini_get('xdebug.file_link_format') || extension_loaded('xdebug')) {
			// Register editor using xdebug's file_link_format option.
			$this->editors['xdebug'] = function($file, $line) {
				return str_replace(array('%f', '%l'), array($file, $line), ini_get('xdebug.file_link_format'));
			};
		}
		// Add the default, local resource search path:
		$this->searchPaths[] = __DIR__ . "/../resources";
	}
	/**
	 * @return int|null
	 */
	public function handle(){
		$this->addDataTable('Aurora_Handler',[
			'Controller'	=> @self::$aurora_handler['controller'],
			'Action'		=> @self::$aurora_handler['action'],
			'Params'		=> @self::$aurora_handler['params'],
		]);
		if (!$this->handleUnconditionally()) {
			// Check conditions for outputting HTML:
			// @todo: Make this more robust
			if(php_sapi_name() === 'cli') {

				// Help users who have been relying on an internal test value
				// fix their code to the proper method
				if (isset($_ENV['whoops-test'])) {
					throw new \Exception(
						'Use handleUnconditionally instead of whoops-test'
						.' environment variable'
					);
				}

				return Handler::DONE;
			}
		}

		// @todo: Make this more dynamic
		$helper = new TemplateHelper;

		$templateFile	= $this->getResource("views/layout.html.php");
		$cssFile		= $this->getResource("css/whoops.base.css");
		$zeptoFile		= $this->getResource("js/zepto.min.js");
		$jsFile			= $this->getResource("js/whoops.base.js");

		if ($this->customCss) {
			$customCssFile = $this->getResource($this->customCss);
		}

		$inspector = $this->getInspector();
		$frames    = $inspector->getFrames();

		$code = $inspector->getException()->getCode();

		if ($inspector->getException() instanceof \ErrorException) {
			$code = Misc::translateErrorCode($code);
		}

		// List of variables that will be passed to the layout template.
		$vars = array(
			"page_title" => $this->getPageTitle(),

			// @todo: Asset compiler
			"stylesheet"	=> file_get_contents($cssFile),
			"zepto"			=> file_get_contents($zeptoFile),
			"javascript"	=> file_get_contents($jsFile),

			// Template paths:
			"header"		=> $this->getResource("views/header.html.php"),
			"frame_list"  => $this->getResource("views/frame_list.html.php"),
			"frame_code"  => $this->getResource("views/frame_code.html.php"),
			"env_details" => $this->getResource("views/env_details.html.php"),

			"title"		    => $this->getPageTitle(),
			"name"           => explode("\\", $inspector->getExceptionName()),
			"message"        => $inspector->getException()->getMessage(),
			"code"           => $code,
			"plain_exception" => Formatter::formatExceptionPlain($inspector),
			"frames"         => $frames,
			"has_frames"     => !!count($frames),
			"handler"        => $this,
			"handlers"       => $this->getRun()->getHandlers(),

			"tables"      => array(
				"Server/Request Data"   => $_SERVER,
				"GET Data"              => $_GET,
				"POST Data"             => $_POST,
				"Files"                 => $_FILES,
				"Cookies"               => $_COOKIE,
				"Session"               => isset($_SESSION) ? $_SESSION:  [],
				"Environment Variables" => $_ENV
			)
		);

		if (isset($customCssFile)) {
			$vars["stylesheet"] .= file_get_contents($customCssFile);
		}

		// Add extra entries list of data tables:
		// @todo: Consolidate addDataTable and addDataTableCallback
		$extraTables = array_map(function($table) {
			return $table instanceof \Closure ? $table() : $table;
		}, $this->getDataTables());
		$vars["tables"] = array_merge($extraTables, $vars["tables"]);

		if(isset(self::$callback)) call_user_func(self::$callback);

		$helper->setVariables($vars);
		$helper->render($templateFile);

		return Handler::QUIT;
	}

	/**
	 * Adds an entry to the list of tables displayed in the template.
	 * The expected data is a simple associative array. Any nested arrays
	 * will be flattened with print_r
	 * @param string $label
	 * @param array  $data
	 */
	public static function addDataTable($label, array $data)
	{
		self::$extraTables[$label] = $data;
	}

	/**
	 * Lazily adds an entry to the list of tables displayed in the table.
	 * The supplied callback argument will be called when the error is rendered,
	 * it should produce a simple associative array. Any nested arrays will
	 * be flattened with print_r.
	 *
	 * @throws InvalidArgumentException If $callback is not callable
	 * @param string   $label
	 * @param callable $callback Callable returning an associative array
	 */
	public function addDataTableCallback($label, /* callable */ $callback)
	{
		if (!is_callable($callback)) {
			throw new InvalidArgumentException('Expecting callback argument to be callable');
		}

		self::$extraTables[$label] = function() use ($callback) {
			try {
				$result = call_user_func($callback);

				// Only return the result if it can be iterated over by foreach().
				return is_array($result) || $result instanceof \Traversable ? $result : [];
			} catch (\Exception $e) {
				// Don't allow failure to break the rendering of the original exception.
				return [];
			}
		};
	}

	/**
	 * Returns all the extra data tables registered with this handler.
	 * Optionally accepts a 'label' parameter, to only return the data
	 * table under that label.
	 * @param string|null $label
	 * @return array[]|callable
	 */
	public function getDataTables($label = null)
	{
		if($label !== null) {
			return isset(self::$extraTables[$label]) ?
				   self::$extraTables[$label] : [];
		}

		return self::$extraTables;
	}

	/**
	 * Allows to disable all attempts to dynamically decide whether to
	 * handle or return prematurely.
	 * Set this to ensure that the handler will perform no matter what.
	 * @param bool|null $value
	 * @return bool|null
	 */
	public function handleUnconditionally($value = null)
	{
		if(func_num_args() == 0) {
			return $this->handleUnconditionally;
		}

		$this->handleUnconditionally = (bool) $value;
	}


	/**
	 * Adds an editor resolver, identified by a string
	 * name, and that may be a string path, or a callable
	 * resolver. If the callable returns a string, it will
	 * be set as the file reference's href attribute.
	 *
	 * @example
	 *  $run->addEditor('macvim', "mvim://open?url=file://%file&line=%line")
	 * @example
	 *   $run->addEditor('remove-it', function($file, $line) {
	 *       unlink($file);
	 *       return "http://stackoverflow.com";
	 *   });
	 * @param  string $identifier
	 * @param  string $resolver
	 */
	public function addEditor($identifier, $resolver)
	{
		$this->editors[$identifier] = $resolver;
	}

	/**
	 * Set the editor to use to open referenced files, by a string
	 * identifier, or a callable that will be executed for every
	 * file reference, with a $file and $line argument, and should
	 * return a string.
	 *
	 * @example
	 *   $run->setEditor(function($file, $line) { return "file:///{$file}"; });
	 * @example
	 *   $run->setEditor('sublime');
	 *
	 * @throws InvalidArgumentException If invalid argument identifier provided
	 * @param string|callable $editor
	 */
	public function setEditor($editor)
	{
		if(!is_callable($editor) && !isset($this->editors[$editor])) {
			throw new InvalidArgumentException(
				"Unknown editor identifier: $editor. Known editors:" .
				implode(",", array_keys($this->editors))
			);
		}

		$this->editor = $editor;
	}

	/**
	 * Given a string file path, and an integer file line,
	 * executes the editor resolver and returns, if available,
	 * a string that may be used as the href property for that
	 * file reference.
	 *
	 * @throws InvalidArgumentException If editor resolver does not return a string
	 * @param  string $filePath
	 * @param  int    $line
	 * @return false|string
	 */
	public function getEditorHref($filePath, $line)
	{
		if($this->editor === null) {
			return false;
		}

		$editor = $this->editor;
		if(is_string($editor)) {
			$editor = $this->editors[$editor];
		}

		if(is_callable($editor)) {
			$editor = call_user_func($editor, $filePath, $line);
		}

		// Check that the editor is a string, and replace the
		// %line and %file placeholders:
		if(!is_string($editor)) {
			throw new InvalidArgumentException(
				__METHOD__ . " should always resolve to a string; got something else instead"
			);
		}

		$editor = str_replace("%line", rawurlencode($line), $editor);
		$editor = str_replace("%file", rawurlencode($filePath), $editor);

		return $editor;
	}

	/**
	 * @param  string $title
	 * @return void
	 */
	public static function setPageTitle($title)
	{
		self::$pageTitle = (string) $title;
	}

	/**
	 * @return string
	 */
	public function getPageTitle()
	{
		return self::$pageTitle;
	}

	/**
	 * Adds a path to the list of paths to be searched for
	 * resources.
	 *
	 * @throws InvalidArgumnetException If $path is not a valid directory
	 *
	 * @param  string $path
	 * @return void
	 */
	public function addResourcePath($path)
	{
		if(!is_dir($path)) {
			throw new InvalidArgumentException(
				"'$path' is not a valid directory"
			);
		}

		array_unshift($this->searchPaths, $path);
	}

	/**
	 * Adds a custom css file to be loaded.
	 *
	 * @param  string $name
	 * @return void
	 */
	public function addCustomCss($name)
	{
		$this->customCss = $name;
	}

	/**
	 * @return array
	 */
	public function getResourcePaths()
	{
		return $this->searchPaths;
	}

	/**
	 * Finds a resource, by its relative path, in all available search paths.
	 * The search is performed starting at the last search path, and all the
	 * way back to the first, enabling a cascading-type system of overrides
	 * for all resources.
	 *
	 * @throws RuntimeException If resource cannot be found in any of the available paths
	 *
	 * @param  string $resource
	 * @return string
	 */
	protected function getResource($resource)
	{
		// If the resource was found before, we can speed things up
		// by caching its absolute, resolved path:
		if(isset($this->resourceCache[$resource])) {
			return $this->resourceCache[$resource];
		}

		// Search through available search paths, until we find the
		// resource we're after:
		foreach($this->searchPaths as $path) {
			$fullPath = $path . "/$resource";

			if(is_file($fullPath)) {
				// Cache the result:
				$this->resourceCache[$resource] = $fullPath;
				return $fullPath;
			}
		}

		// If we got this far, nothing was found.
		throw new RuntimeException(
			"Could not find resource '$resource' in any resource paths."
			. "(searched: " . join(", ", $this->searchPaths). ")"
		);
	}

	/**
	 * @deprecated
	 *
	 * @return string
	 */
	public function getResourcesPath()
	{
		$allPaths = $this->getResourcePaths();

		// Compat: return only the first path added
		return end($allPaths) ?: null;
	}

	/**
	 * @deprecated
	 *
	 * @param  string $resourcesPath
	 * @return void
	 */
	public function setResourcesPath($resourcesPath)
	{
		$this->addResourcePath($resourcesPath);
	}
}
//class_alias('Aurora\Extensions\Whoops\Handler\AuroraHandler', 'Handler');