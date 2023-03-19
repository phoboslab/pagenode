<?php

#
# Pagenode
# http://pagenode.org
#
# (c) Dominic Szablewski
# https://phoboslab.org
#

define('PN_VERSION', '0.1');

if (!defined('PN_DATE_FORMAT'))
	define('PN_DATE_FORMAT', 'M d, Y - H:i:s');

if (!defined('PN_SYNTAX_HIGHLIGHT_LANGS'))
	define('PN_SYNTAX_HIGHLIGHT_LANGS', 'php|js|sql|c');

if (!defined('PN_CACHE_INDEX_PATH'))
	define('PN_CACHE_INDEX_PATH', null);

if (!defined('PN_CACHE_USE_INDICATOR_FILE'))
	define('PN_CACHE_USE_INDICATOR_FILE', false);

if (!defined('PN_CACHE_INDICATOR_FILE'))
	define('PN_CACHE_INDICATOR_FILE', '.git/FETCH_HEAD');

if (!defined('PN_JSON_API_FULL_DEBUG_INFO'))
	define('PN_JSON_API_FULL_DEBUG_INFO', false);

if (defined('PN_TIMEZONE'))
	date_default_timezone_set(PN_TIMEZONE);
else if (!date_default_timezone_get())
	date_default_timezone_set('UTC');



$PN_TimeStart = microtime(true);
header('Content-type: text/html; charset=UTF-8');
define('PN_ABS',
	rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/').'/');


// -----------------------------------------------------------------------------
// Selector Class - provides a query interface for Nodes on the filesystem

class PN_Selector {
	public static $DebugInfo = [];

	protected $path = null, $indexPath = null;
	protected static $IndexCache = [];
	protected static $FoundNodes = 0;

	const SORT_DESC = 'desc';
	const SORT_ASC = 'asc';

	public function __construct($path) {
		$this->path = realpath('./'.$path.'/');
		if (!$this->path || strstr($path, '..') !== false) {
			header("HTTP/1.1 500 Internal Error");
			echo 'select("'.htmlSpecialChars($path).'") does not exist.';
			exit();
		}
		$this->indexPath = 
			(PN_CACHE_INDEX_PATH ?? sys_get_temp_dir()).
			'/pagenode-index-'.md5($this->path).'.json';
	}

	protected function rebuildIndex() {
		$index = [];
		foreach (glob($this->path.'/*.md') as $path) {
			$meta = $this->loadMetaFromFile($path);
			if ($meta['active'] !== false) {
				$keyword = pathInfo($path, PATHINFO_FILENAME);
				$index[$keyword] = $meta;
			}
		}

		if (empty($index)) {
			return $index;
		}

		uasort($index, function ($a, $b) {
			return $b['date'] <=> $a['date'];
		});

		$jsonOpts = JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES;
		$json = json_encode($index, $jsonOpts);
		file_put_contents($this->indexPath, $json);

		return $index;
	}

	protected function indexIsCurrent() {
		if (!file_exists($this->indexPath)) {
			return false;
		}

		$indexTime = filemtime($this->indexPath);
		if (
			PN_CACHE_USE_INDICATOR_FILE &&
			file_exists(PN_CACHE_INDICATOR_FILE)
		) {
			return $indexTime > filemtime(PN_CACHE_INDICATOR_FILE);
		}

		$lastFileTime = 0;
		foreach (glob($this->path.'/*.md') as $f) {
			$lastFileTime = max($lastFileTime, filemtime($f));
		}
		return $indexTime > $lastFileTime;
	}

	protected function getIndex() {
		$timeStart = microtime(true);
		$didRebuild = false;

		if (!isset(self::$IndexCache[$this->path])) {
			if ($this->indexIsCurrent()) {
				$json = file_get_contents($this->indexPath);
				self::$IndexCache[$this->path] = json_decode($json, true);
			}
			else {
				self::$IndexCache[$this->path] = $this->rebuildIndex();
				$didRebuild = true;
			}

			self::$DebugInfo[] = [
				'action' => 'loadIndex',
				'path' => $this->path,
				'indexPath' => $this->indexPath,
				'ms' => round((microtime(true) - $timeStart)*1000, 3),
				'didRebuild' => (int)$didRebuild,
				'cacheMethod' => PN_CACHE_USE_INDICATOR_FILE 
					? 'INDICATOR_FILE'
					: 'NODE_LAST_MODIFIED'
			];
		}

		return self::$IndexCache[$this->path] ?? [];
	}

	protected function loadMetaFromFile($path) {
		$meta = [];
		$file = file_get_contents($path);
		if (preg_match('/(.*?)^---\s*$/ms', $file, $metaSection)) {
			preg_match_all('/^(\w+):(.*)$/m', $metaSection[1], $metaAttribs);
			foreach ($metaAttribs[1] as $i => $key) {
				$meta[$key] = trim($metaAttribs[2][$i]);
			}
		}
		
		$meta['tags'] = !empty($meta['tags'])
			? array_map('trim', explode(',', $meta['tags']))
			: [];
		
		
		if (
			!empty($meta['date']) &&
			preg_match(
				'/(\d{4})[\.\-](\d{2})[\.\-](\d{2})( (\d{2}):(\d{2}))?/',
				$meta['date'],
				$dateMatch
			)
		) {
			$y = $dateMatch[1];
			$m = $dateMatch[2];
			$d = $dateMatch[3];
			$h = !empty($dateMatch[5]) ? $dateMatch[5] : 0;
			$i = !empty($dateMatch[6]) ? $dateMatch[6] : 0;
			$meta['date'] = mktime($h, $i, 0, $m, $d, $y);
		}
		else {
			$meta['date'] = filemtime($path);
		}

		$meta['active'] = empty($meta['active']) || $meta['active'] !== 'false';

		return $meta;
	}


	public static function FoundNodes() {
		return self::$FoundNodes;
	}

	public function one($params = [], $raw = false) {
		$nodes = $this->query('date', self::SORT_DESC, 1, $params, $raw);
		return !empty($nodes) ? $nodes[0] : null;
	}

	public function newest($count = 0, $params = [], $raw = false) {
		return $this->query('date', self::SORT_DESC, $count, $params, $raw);
	}

	public function oldest($count = 0, $params = [], $raw = false) {
		return $this->query('date', self::SORT_ASC, $count, $params, $raw);
	}

	public function query($sort, $order, $count, $params, $raw = false) {
		if (!$this->path) {
			return [];
		}

		$index = $this->getIndex();

		$timeStart = microtime(true);
		$scannedNodes = count($index);

		// Filter by keyword. Since keywords are unique, we can simply index
		// by it, returning only one node.

		if (!empty($params['keyword'])) {
			$index = !empty($index[$params['keyword']])
				? [$params['keyword'] => $index[$params['keyword']]]
				: [];
		}


		// Filter by date. Allow to become more granual by specifying either
		// just year, year & month or year & month & day.

		if (!empty($params['date'])) {
			$y = $params['date'][0] ?? $params['date'];
			$m = $params['date'][1] ?? null;
			$d = $params['date'][2] ?? null;
			if (preg_match('/(\d{4}).(\d{2}).(\d{2})/', $y, $match)) {
				$y = $match[1];
				$m = $match[2];
				$d = $match[3];
			}
			$start = mktime(0, 0, 0, ($m ? $m : 1), ($d ? $d : 1), $y);
			$end = mktime(23, 59, 59, ($m ? $m : 12),  ($d ? $d : 31), $y);

			$index = array_filter($index, function($n) use ($start, $end) {
				return $n['date'] >= $start && $n['date'] <= $end;
			});
		}


		// Filter by tags. Only return nodes that match all given tags.

		if (!empty($params['tags'])) {
			$tags = !is_array($params['tags']) 
				? array_map('trim', explode(',', $params['tags']))
				: $params['tags'];
			$index = array_filter($index, function($n) use ($tags) {
				return !array_udiff($tags, $n['tags'], 'strcasecmp');
			});
		}


		// Filter by arbitrary properties

		if (!empty($params['meta'])) {
			$meta = $params['meta'];
			$index = array_filter($index, function($n) use ($meta) {
				foreach ($meta as $key => $value) {
					if (!isset($n[$key]) || $n[$key] !== $value) {
						return false;
					}
				}
				return true;
			});
		}

		// Filter using a custom filter function

		if (!empty($params['filter']) && is_callable($params['filter'])) {
			$index = array_filter($index, $params['filter']);
		}


		// Sort by any property

		if ($sort === 'date' && $order === self::SORT_DESC) {
			// Nothing to do here; index is sorted by date, desc by default
		}
		else {
			if ($order === self::SORT_ASC) {
				uasort($index, function ($a, $b) use ($sort) {
					return ($a[$sort] ?? INF) <=> ($b[$sort] ?? INF);
				});
			}
			else {
				uasort($index, function ($a, $b) use ($sort) {
					return ($b[$sort] ?? 0) <=> ($a[$sort] ?? 0);
				});
			}
		}
		

		// Keep track of the total nodes found with the given filter params

		self::$FoundNodes = count($index);
		

		// Slice and Paginate

		if ($count) {
			$offset = ($params['page'] ?? 0) * $count;			
			$index = array_slice($index, $offset, $count, true);
		}


		// Create Nodes

		$nodes = [];
		foreach ($index as $keyword => $meta) {
			$nodePath = $this->path.'/'.$keyword.'.md';
			$nodes[] = new PN_Node($nodePath, $keyword, $meta, $raw);
		}

		self::$DebugInfo[] = [
			'action' => 'query',
			'path' => $this->path,
			'ms' => round((microtime(true) - $timeStart)*1000, 3),
			'scanned' => $scannedNodes,
			'returned' => count($nodes),
			'params' => $params
		];

		return $nodes;
	}
}


// -----------------------------------------------------------------------------
// DateTime class - a simple wrapper for timestamps

class PN_DateTime {
	protected $timestamp;
	public function __construct($timestamp) {
		$this->timestamp = $timestamp;
	}

	public function format($format = PN_DATE_FORMAT) {
		return htmlSpecialChars(date($format, $this->timestamp));
	}

	public function __toString() {
		return $this->format();
	}
}


// -----------------------------------------------------------------------------
// Node Class - each Node instance represents a single file

class PN_Node {
	public static $DebugOpenedNodes = [];

	public $keyword, $tags = [], $date;
	protected $path, $meta = [], $body = null, $raw = false;

	public function __construct($path, $keyword, $meta, $raw = false) {
		$this->raw = $raw;
		$this->path = $path;
		$this->keyword = pathInfo($path, PATHINFO_FILENAME);
		$this->date = $raw ? $meta['date'] : new PN_DateTime($meta['date']);
		$this->meta = $meta;

		if (!$raw) {
			foreach ($meta['tags'] as $t) {
				$this->tags[] = htmlSpecialChars($t);
			}
		}
		else {
			$this->tags = $meta['tags'];
		}
	}

	protected function loadBody() {
		self::$DebugOpenedNodes[] = $this->path;
		$file = file_get_contents($this->path);

		$markdown = (preg_match('/^---\s*$(.*)/ms', $file, $m))
			? $m[1]
			: $file;

		if ($this->raw) {
			return $markdown;
		}
		else {
			return !empty(PN_SYNTAX_HIGHLIGHT_LANGS)
				? PN_ParsedownSyntaxHighlight::instance()->text($markdown)
				: Parsedown::instance()->text($markdown);
		}
	}

	public function hasTag($tag) {
		return in_array($tag, $this->meta['tags']);
	}

	public function __get($name) {
		if ($name === 'body') {
			if (!$this->body) {
				$this->body = $this->loadBody();
			}
			return $this->body;
		}
		else if (isset($this->meta[$name])) {
			return $this->raw
				? $this->meta[$name] 
				: htmlSpecialChars($this->meta[$name]);
		}

		return null;
	}
}


// -----------------------------------------------------------------------------
// Router Class - handles routes and dispatch

class PN_Router {
	public static $Routes = [];

	public static function AddRoute($path, $resolver) {
		$r = str_replace('/', '\\/', $path);
		$r = str_replace('*', '.*?', $r);
		$r = preg_replace('/{(\w+)}/', '(?<$1>[^\\/]+?)', $r);
		$regexp = '/^'.$r.'$/';

		self::$Routes[$path] = [
			'regexp' => $regexp,
			'resolver' => $resolver
		];
	}

	public static function Dispatch($request) {
		foreach (self::$Routes as $path => $r) {
			if (preg_match($r['regexp'], $request, $m)) {
				$found = self::Resolve($r['resolver'], $m);
				return ($found && $path !== '/*');
			}
		}
		return self::ErrorNotFound();
	}

	public static function Resolve($resolver, $regexpMatch, $recurse = true) {
		$params = array_filter($regexpMatch, function($key) {
			return !is_int($key); 
		}, ARRAY_FILTER_USE_KEY);

		if (call_user_func_array($resolver, $params) !== false) {
			return true;
		};
		
		return self::ErrorNotFound($recurse);
	}

	public static function ErrorNotFound($recurse = true) {
		if ($recurse && !empty(self::$Routes['/*'])) {
			self::Resolve(self::$Routes['/*']['resolver'], [], false);
		}
		else {
			header("HTTP/1.1 404 Not Found");
			echo "Not Found";
		}
		return false;
	}
}


// -----------------------------------------------------------------------------
// Generic Syntax Highlighting extension for Parsedown

class PN_ParsedownSyntaxHighlight extends Parsedown {
	public static function SyntaxHighlight($s) {
		$s = htmlSpecialChars($s)."\n";
		$s = str_replace('\\\\','\\\\<e>', $s); // break escaped backslashes

		$tokens = [];
		$transforms = [
			// Insert helpers to find regexps
			'/
				([\[({=:+,]\s*)
					\/
				(?![\/\*])
			/x'
				=> '$1<h>/',

			// Extract Comments, Strings & Regexps, insert them into $tokens
			// and return the index
			'/(
				\/\*.*?\*\/|
				\/\/.*?\n|
				\#.*?\n|
				--.*?\n|
				(?<!\\\)&quot;.*?(?<!\\\)&quot;|
				(?<!\\\)\'(.*?)(?<!\\\)\'|
				(?<!\\\)<h>\/.+?(?<!\\\)\/\w*
			)/sx'
				=> function($m) use (&$tokens) {
					$id = '<r'.count($tokens).'>';
					$block = $m[1];

					if ($block[0] === '&' || $block[0] === "'") {
						$type = 'string';
					}
					else if ($block[0] === '<') {
						$type = 'regexp';
					}
					else {
						$type = 'comment';
					}
					$tokens[$id] = '<span class="'.$type.'">'.$block.'</span>';
					return $id;
				},

			// Punctuation
			'/((
				&\w+;|
				[-\/+*=?:.,;()\[\]{}|%^!]
			)+)/x'
				=> '<span class="punct">$1</span>',

			// Numbers (also look for Hex encoding)
			'/(?<!\w)(
				0x[\da-f]+|
				\d+
			)(?!\w)/ix'
				=> '<span class="number">$1</span>',

			// Keywords
			'/(?<!\w|\$)(
				and|or|xor|not|for|do|while|foreach|as|endfor|endwhile|break|
				endforeach|continue|return|die|exit|if|then|else|elsif|elseif|
				endif|new|delete|try|throw|catch|finally|switch|case|default|
				goto|class|function|extends|this|self|parent|public|private|
				protected|published|friend|virtual|
				string|array|object|resource|var|let|bool|boolean|int|integer|
				float|double|real|char|short|long|const|static|global|
				enum|struct|typedef|signed|unsigned|union|extern|true|false|
				null|void
			)(?!\w|=")/ix'
				=> '<span class="keyword">$1</span>',

			// PHP-Style Vars: $var
			'/(?<!\w)(
				\$(\-&gt;|\w)+
			)(?!\w)/ix'
				=> '<span class="var">$1</span>',

			// Make the bold assumption that an all uppercase word has a 
            // special meaning
			'/(?<!\w|\$|>)(
				[A-Z_][A-Z_0-9]+
			)(?!\w)/x'
				  => '<span class="def">$1</span>'
		];

		foreach ($transforms as $search => $replace) {
			$s = is_string($replace)
				? preg_replace($search, $replace, $s)
				: preg_replace_callback($search, $replace, $s);
		}

		// Paste the comments and strings back in again
		$s = strtr($s, $tokens);

		// Delete the escaped backslash breaker and replace tabs with 4 spaces
		$s = str_replace(['<e>', '<h>', "\t" ], ['', '', '    '], $s);

		return trim($s, "\n\r");
	}

	protected function blockFencedCodeComplete($Block) {
		$class = $Block['element']['element']['attributes']['class'] ?? null;
		$re = '/^language-('.PN_SYNTAX_HIGHLIGHT_LANGS.')$/';
		if (empty($class) || !preg_match($re, $class)) {
			return $Block;
		}
		
		$text = $Block['element']['element']['text'];
		unset($Block['element']['element']['text']);
		$Block['element']['element']['rawHtml'] = self::SyntaxHighlight($text);
		$Block['element']['element']['allowRawHtmlInSafeMode'] = true;
		return $Block;
	}
}


// -----------------------------------------------------------------------------
// mb_strlen polyfill for Parsedown when mbstring extension is not installed

if (!function_exists('mb_strlen')) {
	function mb_strlen($s) {
		$byteLength = strlen($s);
		for ($q = 0, $i = 0; $i < $byteLength; $i++, $q++) {
			$c = ord($s[$i]);
			if ($c >= 0 && $c <= 127) { $i += 0; }
			else if (($c & 0xE0) == 0xC0) { $i += 1; }
			else if (($c & 0xF0) == 0xE0) { $i += 2; }
			else if (($c & 0xF8) == 0xF0) { $i += 3; }
			else return $byteLength; //invalid utf8
		}
		return $q;
	}
}


// -----------------------------------------------------------------------------
// PAGENODE Public API

function select($path = '') {
	return new PN_Selector($path);
}

function foundNodes() {
	return PN_Selector::FoundNodes();
}

function route($path, $resolver = null) {
	PN_Router::AddRoute($path, $resolver);
}

function reroute($source, $target) {
	route($source, function() use ($target) {
		$args = func_get_args();
		$target = preg_replace_callback(
			'/{(\w+)}/',
			function($m) use ($args) { return $args[$m[1] - 1] ?? ''; },
			$target
		);
		dispatch($target);
	});
}

function redirect($path = '/', $params = []) {
	$query = !empty($params) 
		? '?'.http_build_query($params) 
		: '';
	header('Location: '.$path.$query);
	exit();
}

function dispatch($request = null) {
	if ($request === null) {
		$request = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
		$request = '/'.substr($request, strlen(PN_ABS));
	}

	$found = PN_Router::Dispatch($request);
}

function getDebugInfo() {
	global $PN_TimeStart;
	return [
		'totalRuntime' => (microtime(true) - $PN_TimeStart)*1000,
		'selctorInfo' => PN_Selector::$DebugInfo,
		'openedNodes' => PN_Node::$DebugOpenedNodes
	];
}

function printDebugInfo() {
	echo "<pre>\n".htmlSpecialChars(print_r(getDebugInfo(), true))."</pre>";
}


// -----------------------------------------------------------------------------
// PAGENODE JSON Route, disabled by default

if (defined('PN_JSON_API_PATH')) {
	route(PN_JSON_API_PATH, function(){
		$nodes = select($_GET['path'] ?? '')->query(
			$_GET['sort'] ?? 'date', 
			$_GET['order'] ?? 'desc', 
			$_GET['count'] ?? 0, 
			[
				'keyword' => $_GET['keyword'] ?? null,
				'date' => $_GET['date'] ?? null,
				'tags' => $_GET['tags'] ?? null,
				'meta' => $_GET['meta'] ?? null,
				'page' => $_GET['page'] ?? null
			],
			true
		);

		$fields = !empty($_GET['fields']) 
			? array_map('trim', explode(',', $_GET['fields']))
			: ['keyword'];

		header('Content-type: application/json; charset=UTF-8');
		echo json_encode([
			'nodes' => array_map(function($n) use ($fields) {
				$ret = [];
				foreach ($fields as $f) {
					$ret[$f] = $n->$f;
				}
				return $ret;
			}, $nodes), 
			'info' => PN_JSON_API_FULL_DEBUG_INFO 
				? getDebugInfo()
				: ['totalRuntime' => getDebugInfo()['totalRuntime']]
		], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
	});
}




// -----------------------------------------------------------------------------
// Parsedown Library

#
#
# Parsedown
# http://parsedown.org
#
# (c) Emanuil Rusev
# http://erusev.com
#
# For the full license information, view the LICENSE file that was distributed
# with this source code.
#
#

class Parsedown
{
	# ~

	const version = '1.8.0-beta-5';

	# ~

	function text($text)
	{
		$Elements = $this->textElements($text);

		# convert to markup
		$markup = $this->elements($Elements);

		# trim line breaks
		$markup = trim($markup, "\n");

		return $markup;
	}

	protected function textElements($text)
	{
		# make sure no definitions are set
		$this->DefinitionData = array();

		# standardize line breaks
		$text = str_replace(array("\r\n", "\r"), "\n", $text);

		# remove surrounding line breaks
		$text = trim($text, "\n");

		# split text into lines
		$lines = explode("\n", $text);

		# iterate through lines to identify blocks
		return $this->linesElements($lines);
	}

	#
	# Setters
	#

	function setBreaksEnabled($breaksEnabled)
	{
		$this->breaksEnabled = $breaksEnabled;

		return $this;
	}

	protected $breaksEnabled;

	function setMarkupEscaped($markupEscaped)
	{
		$this->markupEscaped = $markupEscaped;

		return $this;
	}

	protected $markupEscaped;

	function setUrlsLinked($urlsLinked)
	{
		$this->urlsLinked = $urlsLinked;

		return $this;
	}

	protected $urlsLinked = true;

	function setSafeMode($safeMode)
	{
		$this->safeMode = (bool) $safeMode;

		return $this;
	}

	protected $safeMode;

	function setStrictMode($strictMode)
	{
		$this->strictMode = (bool) $strictMode;

		return $this;
	}

	protected $strictMode;

	protected $safeLinksWhitelist = array(
		'http://',
		'https://',
		'ftp://',
		'ftps://',
		'mailto:',
		'data:image/png;base64,',
		'data:image/gif;base64,',
		'data:image/jpeg;base64,',
		'irc:',
		'ircs:',
		'git:',
		'ssh:',
		'news:',
		'steam:',
	);

	#
	# Lines
	#

	protected $BlockTypes = array(
		'#' => array('Header'),
		'*' => array('Rule', 'List'),
		'+' => array('List'),
		'-' => array('SetextHeader', 'Table', 'Rule', 'List'),
		'0' => array('List'),
		'1' => array('List'),
		'2' => array('List'),
		'3' => array('List'),
		'4' => array('List'),
		'5' => array('List'),
		'6' => array('List'),
		'7' => array('List'),
		'8' => array('List'),
		'9' => array('List'),
		':' => array('Table'),
		'<' => array('Comment', 'Markup'),
		'=' => array('SetextHeader'),
		'>' => array('Quote'),
		'[' => array('Reference'),
		'_' => array('Rule'),
		'`' => array('FencedCode'),
		'|' => array('Table'),
		'~' => array('FencedCode'),
	);

	# ~

	protected $unmarkedBlockTypes = array(
		'Code',
	);

	#
	# Blocks
	#

	protected function lines(array $lines)
	{
		return $this->elements($this->linesElements($lines));
	}

	protected function linesElements(array $lines)
	{
		$Elements = array();
		$CurrentBlock = null;

		foreach ($lines as $line)
		{
			if (chop($line) === '')
			{
				if (isset($CurrentBlock))
				{
					$CurrentBlock['interrupted'] = (isset($CurrentBlock['interrupted'])
						? $CurrentBlock['interrupted'] + 1 : 1
					);
				}

				continue;
			}

			while (($beforeTab = strstr($line, "\t", true)) !== false)
			{
				$shortage = 4 - mb_strlen($beforeTab, 'utf-8') % 4;

				$line = $beforeTab
					. str_repeat(' ', $shortage)
					. substr($line, strlen($beforeTab) + 1)
				;
			}

			$indent = strspn($line, ' ');

			$text = $indent > 0 ? substr($line, $indent) : $line;

			# ~

			$Line = array('body' => $line, 'indent' => $indent, 'text' => $text);

			# ~

			if (isset($CurrentBlock['continuable']))
			{
				$methodName = 'block' . $CurrentBlock['type'] . 'Continue';
				$Block = $this->$methodName($Line, $CurrentBlock);

				if (isset($Block))
				{
					$CurrentBlock = $Block;

					continue;
				}
				else
				{
					if ($this->isBlockCompletable($CurrentBlock['type']))
					{
						$methodName = 'block' . $CurrentBlock['type'] . 'Complete';
						$CurrentBlock = $this->$methodName($CurrentBlock);
					}
				}
			}

			# ~

			$marker = $text[0];

			# ~

			$blockTypes = $this->unmarkedBlockTypes;

			if (isset($this->BlockTypes[$marker]))
			{
				foreach ($this->BlockTypes[$marker] as $blockType)
				{
					$blockTypes []= $blockType;
				}
			}

			#
			# ~

			foreach ($blockTypes as $blockType)
			{
				$Block = $this->{"block$blockType"}($Line, $CurrentBlock);

				if (isset($Block))
				{
					$Block['type'] = $blockType;

					if ( ! isset($Block['identified']))
					{
						if (isset($CurrentBlock))
						{
							$Elements[] = $this->extractElement($CurrentBlock);
						}

						$Block['identified'] = true;
					}

					if ($this->isBlockContinuable($blockType))
					{
						$Block['continuable'] = true;
					}

					$CurrentBlock = $Block;

					continue 2;
				}
			}

			# ~

			if (isset($CurrentBlock) and $CurrentBlock['type'] === 'Paragraph')
			{
				$Block = $this->paragraphContinue($Line, $CurrentBlock);
			}

			if (isset($Block))
			{
				$CurrentBlock = $Block;
			}
			else
			{
				if (isset($CurrentBlock))
				{
					$Elements[] = $this->extractElement($CurrentBlock);
				}

				$CurrentBlock = $this->paragraph($Line);

				$CurrentBlock['identified'] = true;
			}
		}

		# ~

		if (isset($CurrentBlock['continuable']) and $this->isBlockCompletable($CurrentBlock['type']))
		{
			$methodName = 'block' . $CurrentBlock['type'] . 'Complete';
			$CurrentBlock = $this->$methodName($CurrentBlock);
		}

		# ~

		if (isset($CurrentBlock))
		{
			$Elements[] = $this->extractElement($CurrentBlock);
		}

		# ~

		return $Elements;
	}

	protected function extractElement(array $Component)
	{
		if ( ! isset($Component['element']))
		{
			if (isset($Component['markup']))
			{
				$Component['element'] = array('rawHtml' => $Component['markup']);
			}
			elseif (isset($Component['hidden']))
			{
				$Component['element'] = array();
			}
		}

		return $Component['element'];
	}

	protected function isBlockContinuable($Type)
	{
		return method_exists($this, 'block' . $Type . 'Continue');
	}

	protected function isBlockCompletable($Type)
	{
		return method_exists($this, 'block' . $Type . 'Complete');
	}

	#
	# Code

	protected function blockCode($Line, $Block = null)
	{
		if (isset($Block) and $Block['type'] === 'Paragraph' and ! isset($Block['interrupted']))
		{
			return;
		}

		if ($Line['indent'] >= 4)
		{
			$text = substr($Line['body'], 4);

			$Block = array(
				'element' => array(
					'name' => 'pre',
					'element' => array(
						'name' => 'code',
						'text' => $text,
					),
				),
			);

			return $Block;
		}
	}

	protected function blockCodeContinue($Line, $Block)
	{
		if ($Line['indent'] >= 4)
		{
			if (isset($Block['interrupted']))
			{
				$Block['element']['element']['text'] .= str_repeat("\n", $Block['interrupted']);

				unset($Block['interrupted']);
			}

			$Block['element']['element']['text'] .= "\n";

			$text = substr($Line['body'], 4);

			$Block['element']['element']['text'] .= $text;

			return $Block;
		}
	}

	protected function blockCodeComplete($Block)
	{
		return $Block;
	}

	#
	# Comment

	protected function blockComment($Line)
	{
		if ($this->markupEscaped or $this->safeMode)
		{
			return;
		}

		if (strpos($Line['text'], '<!--') === 0)
		{
			$Block = array(
				'element' => array(
					'rawHtml' => $Line['body'],
					'autobreak' => true,
				),
			);

			if (strpos($Line['text'], '-->') !== false)
			{
				$Block['closed'] = true;
			}

			return $Block;
		}
	}

	protected function blockCommentContinue($Line, array $Block)
	{
		if (isset($Block['closed']))
		{
			return;
		}

		$Block['element']['rawHtml'] .= "\n" . $Line['body'];

		if (strpos($Line['text'], '-->') !== false)
		{
			$Block['closed'] = true;
		}

		return $Block;
	}

	#
	# Fenced Code

	protected function blockFencedCode($Line)
	{
		$marker = $Line['text'][0];

		$openerLength = strspn($Line['text'], $marker);

		if ($openerLength < 3)
		{
			return;
		}

		$infostring = trim(substr($Line['text'], $openerLength), "\t ");

		if (strpos($infostring, '`') !== false)
		{
			return;
		}

		$Element = array(
			'name' => 'code',
			'text' => '',
		);

		if ($infostring !== '')
		{
			$Element['attributes'] = array('class' => "language-$infostring");
		}

		$Block = array(
			'char' => $marker,
			'openerLength' => $openerLength,
			'element' => array(
				'name' => 'pre',
				'element' => $Element,
			),
		);

		return $Block;
	}

	protected function blockFencedCodeContinue($Line, $Block)
	{
		if (isset($Block['complete']))
		{
			return;
		}

		if (isset($Block['interrupted']))
		{
			$Block['element']['element']['text'] .= str_repeat("\n", $Block['interrupted']);

			unset($Block['interrupted']);
		}

		if (($len = strspn($Line['text'], $Block['char'])) >= $Block['openerLength']
			and chop(substr($Line['text'], $len), ' ') === ''
		) {
			$Block['element']['element']['text'] = substr($Block['element']['element']['text'], 1);

			$Block['complete'] = true;

			return $Block;
		}

		$Block['element']['element']['text'] .= "\n" . $Line['body'];

		return $Block;
	}

	protected function blockFencedCodeComplete($Block)
	{
		return $Block;
	}

	#
	# Header

	protected function blockHeader($Line)
	{
		$level = strspn($Line['text'], '#');

		if ($level > 6)
		{
			return;
		}

		$text = trim($Line['text'], '#');

		if ($this->strictMode and isset($text[0]) and $text[0] !== ' ')
		{
			return;
		}

		$text = trim($text, ' ');

		$Block = array(
			'element' => array(
				'name' => 'h' . min(6, $level),
				'handler' => array(
					'function' => 'lineElements',
					'argument' => $text,
					'destination' => 'elements',
				)
			),
		);

		return $Block;
	}

	#
	# List

	protected function blockList($Line, array $CurrentBlock = null)
	{
		list($name, $pattern) = $Line['text'][0] <= '-' ? array('ul', '[*+-]') : array('ol', '[0-9]{1,9}+[.\)]');

		if (preg_match('/^('.$pattern.'([ ]++|$))(.*+)/', $Line['text'], $matches))
		{
			$contentIndent = strlen($matches[2]);

			if ($contentIndent >= 5)
			{
				$contentIndent -= 1;
				$matches[1] = substr($matches[1], 0, -$contentIndent);
				$matches[3] = str_repeat(' ', $contentIndent) . $matches[3];
			}
			elseif ($contentIndent === 0)
			{
				$matches[1] .= ' ';
			}

			$markerWithoutWhitespace = strstr($matches[1], ' ', true);

			$Block = array(
				'indent' => $Line['indent'],
				'pattern' => $pattern,
				'data' => array(
					'type' => $name,
					'marker' => $matches[1],
					'markerType' => ($name === 'ul' ? $markerWithoutWhitespace : substr($markerWithoutWhitespace, -1)),
				),
				'element' => array(
					'name' => $name,
					'elements' => array(),
				),
			);
			$Block['data']['markerTypeRegex'] = preg_quote($Block['data']['markerType'], '/');

			if ($name === 'ol')
			{
				$listStart = ltrim(strstr($matches[1], $Block['data']['markerType'], true), '0') ?: '0';

				if ($listStart !== '1')
				{
					if (
						isset($CurrentBlock)
						and $CurrentBlock['type'] === 'Paragraph'
						and ! isset($CurrentBlock['interrupted'])
					) {
						return;
					}

					$Block['element']['attributes'] = array('start' => $listStart);
				}
			}

			$Block['li'] = array(
				'name' => 'li',
				'handler' => array(
					'function' => 'li',
					'argument' => !empty($matches[3]) ? array($matches[3]) : array(),
					'destination' => 'elements'
				)
			);

			$Block['element']['elements'] []= & $Block['li'];

			return $Block;
		}
	}

	protected function blockListContinue($Line, array $Block)
	{
		if (isset($Block['interrupted']) and empty($Block['li']['handler']['argument']))
		{
			return null;
		}

		$requiredIndent = ($Block['indent'] + strlen($Block['data']['marker']));

		if ($Line['indent'] < $requiredIndent
			and (
				(
					$Block['data']['type'] === 'ol'
					and preg_match('/^[0-9]++'.$Block['data']['markerTypeRegex'].'(?:[ ]++(.*)|$)/', $Line['text'], $matches)
				) or (
					$Block['data']['type'] === 'ul'
					and preg_match('/^'.$Block['data']['markerTypeRegex'].'(?:[ ]++(.*)|$)/', $Line['text'], $matches)
				)
			)
		) {
			if (isset($Block['interrupted']))
			{
				$Block['li']['handler']['argument'] []= '';

				$Block['loose'] = true;

				unset($Block['interrupted']);
			}

			unset($Block['li']);

			$text = isset($matches[1]) ? $matches[1] : '';

			$Block['indent'] = $Line['indent'];

			$Block['li'] = array(
				'name' => 'li',
				'handler' => array(
					'function' => 'li',
					'argument' => array($text),
					'destination' => 'elements'
				)
			);

			$Block['element']['elements'] []= & $Block['li'];

			return $Block;
		}
		elseif ($Line['indent'] < $requiredIndent and $this->blockList($Line))
		{
			return null;
		}

		if ($Line['text'][0] === '[' and $this->blockReference($Line))
		{
			return $Block;
		}

		if ($Line['indent'] >= $requiredIndent)
		{
			if (isset($Block['interrupted']))
			{
				$Block['li']['handler']['argument'] []= '';

				$Block['loose'] = true;

				unset($Block['interrupted']);
			}

			$text = substr($Line['body'], $requiredIndent);

			$Block['li']['handler']['argument'] []= $text;

			return $Block;
		}

		if ( ! isset($Block['interrupted']))
		{
			$text = preg_replace('/^[ ]{0,'.$requiredIndent.'}+/', '', $Line['body']);

			$Block['li']['handler']['argument'] []= $text;

			return $Block;
		}
	}

	protected function blockListComplete(array $Block)
	{
		if (isset($Block['loose']))
		{
			foreach ($Block['element']['elements'] as &$li)
			{
				if (end($li['handler']['argument']) !== '')
				{
					$li['handler']['argument'] []= '';
				}
			}
		}

		return $Block;
	}

	#
	# Quote

	protected function blockQuote($Line)
	{
		if (preg_match('/^>[ ]?+(.*+)/', $Line['text'], $matches))
		{
			$Block = array(
				'element' => array(
					'name' => 'blockquote',
					'handler' => array(
						'function' => 'linesElements',
						'argument' => (array) $matches[1],
						'destination' => 'elements',
					)
				),
			);

			return $Block;
		}
	}

	protected function blockQuoteContinue($Line, array $Block)
	{
		if (isset($Block['interrupted']))
		{
			return;
		}

		if ($Line['text'][0] === '>' and preg_match('/^>[ ]?+(.*+)/', $Line['text'], $matches))
		{
			$Block['element']['handler']['argument'] []= $matches[1];

			return $Block;
		}

		if ( ! isset($Block['interrupted']))
		{
			$Block['element']['handler']['argument'] []= $Line['text'];

			return $Block;
		}
	}

	#
	# Rule

	protected function blockRule($Line)
	{
		$marker = $Line['text'][0];

		if (substr_count($Line['text'], $marker) >= 3 and chop($Line['text'], " $marker") === '')
		{
			$Block = array(
				'element' => array(
					'name' => 'hr',
				),
			);

			return $Block;
		}
	}

	#
	# Setext

	protected function blockSetextHeader($Line, array $Block = null)
	{
		if ( ! isset($Block) or $Block['type'] !== 'Paragraph' or isset($Block['interrupted']))
		{
			return;
		}

		if ($Line['indent'] < 4 and chop(chop($Line['text'], ' '), $Line['text'][0]) === '')
		{
			$Block['element']['name'] = $Line['text'][0] === '=' ? 'h1' : 'h2';

			return $Block;
		}
	}

	#
	# Markup

	protected function blockMarkup($Line)
	{
		if ($this->markupEscaped or $this->safeMode)
		{
			return;
		}

		if (preg_match('/^<[\/]?+(\w*)(?:[ ]*+'.$this->regexHtmlAttribute.')*+[ ]*+(\/)?>/', $Line['text'], $matches))
		{
			$element = strtolower($matches[1]);

			if (in_array($element, $this->textLevelElements))
			{
				return;
			}

			$Block = array(
				'name' => $matches[1],
				'element' => array(
					'rawHtml' => $Line['text'],
					'autobreak' => true,
				),
			);

			return $Block;
		}
	}

	protected function blockMarkupContinue($Line, array $Block)
	{
		if (isset($Block['closed']) or isset($Block['interrupted']))
		{
			return;
		}

		$Block['element']['rawHtml'] .= "\n" . $Line['body'];

		return $Block;
	}

	#
	# Reference

	protected function blockReference($Line)
	{
		if (strpos($Line['text'], ']') !== false
			and preg_match('/^\[(.+?)\]:[ ]*+<?(\S+?)>?(?:[ ]+["\'(](.+)["\')])?[ ]*+$/', $Line['text'], $matches)
		) {
			$id = strtolower($matches[1]);

			$Data = array(
				'url' => $matches[2],
				'title' => isset($matches[3]) ? $matches[3] : null,
			);

			$this->DefinitionData['Reference'][$id] = $Data;

			$Block = array(
				'element' => array(),
			);

			return $Block;
		}
	}

	#
	# Table

	protected function blockTable($Line, array $Block = null)
	{
		if ( ! isset($Block) or $Block['type'] !== 'Paragraph' or isset($Block['interrupted']))
		{
			return;
		}

		if (
			strpos($Block['element']['handler']['argument'], '|') === false
			and strpos($Line['text'], '|') === false
			and strpos($Line['text'], ':') === false
			or strpos($Block['element']['handler']['argument'], "\n") !== false
		) {
			return;
		}

		if (chop($Line['text'], ' -:|') !== '')
		{
			return;
		}

		$alignments = array();

		$divider = $Line['text'];

		$divider = trim($divider);
		$divider = trim($divider, '|');

		$dividerCells = explode('|', $divider);

		foreach ($dividerCells as $dividerCell)
		{
			$dividerCell = trim($dividerCell);

			if ($dividerCell === '')
			{
				return;
			}

			$alignment = null;

			if ($dividerCell[0] === ':')
			{
				$alignment = 'left';
			}

			if (substr($dividerCell, - 1) === ':')
			{
				$alignment = $alignment === 'left' ? 'center' : 'right';
			}

			$alignments []= $alignment;
		}

		# ~

		$HeaderElements = array();

		$header = $Block['element']['handler']['argument'];

		$header = trim($header);
		$header = trim($header, '|');

		$headerCells = explode('|', $header);

		if (count($headerCells) !== count($alignments))
		{
			return;
		}

		foreach ($headerCells as $index => $headerCell)
		{
			$headerCell = trim($headerCell);

			$HeaderElement = array(
				'name' => 'th',
				'handler' => array(
					'function' => 'lineElements',
					'argument' => $headerCell,
					'destination' => 'elements',
				)
			);

			if (isset($alignments[$index]))
			{
				$alignment = $alignments[$index];

				$HeaderElement['attributes'] = array(
					'style' => "text-align: $alignment;",
				);
			}

			$HeaderElements []= $HeaderElement;
		}

		# ~

		$Block = array(
			'alignments' => $alignments,
			'identified' => true,
			'element' => array(
				'name' => 'table',
				'elements' => array(),
			),
		);

		$Block['element']['elements'] []= array(
			'name' => 'thead',
		);

		$Block['element']['elements'] []= array(
			'name' => 'tbody',
			'elements' => array(),
		);

		$Block['element']['elements'][0]['elements'] []= array(
			'name' => 'tr',
			'elements' => $HeaderElements,
		);

		return $Block;
	}

	protected function blockTableContinue($Line, array $Block)
	{
		if (isset($Block['interrupted']))
		{
			return;
		}

		if (count($Block['alignments']) === 1 or $Line['text'][0] === '|' or strpos($Line['text'], '|'))
		{
			$Elements = array();

			$row = $Line['text'];

			$row = trim($row);
			$row = trim($row, '|');

			preg_match_all('/(?:(\\\\[|])|[^|`]|`[^`]++`|`)++/', $row, $matches);

			$cells = array_slice($matches[0], 0, count($Block['alignments']));

			foreach ($cells as $index => $cell)
			{
				$cell = trim($cell);

				$Element = array(
					'name' => 'td',
					'handler' => array(
						'function' => 'lineElements',
						'argument' => $cell,
						'destination' => 'elements',
					)
				);

				if (isset($Block['alignments'][$index]))
				{
					$Element['attributes'] = array(
						'style' => 'text-align: ' . $Block['alignments'][$index] . ';',
					);
				}

				$Elements []= $Element;
			}

			$Element = array(
				'name' => 'tr',
				'elements' => $Elements,
			);

			$Block['element']['elements'][1]['elements'] []= $Element;

			return $Block;
		}
	}

	#
	# ~
	#

	protected function paragraph($Line)
	{
		return array(
			'type' => 'Paragraph',
			'element' => array(
				'name' => 'p',
				'handler' => array(
					'function' => 'lineElements',
					'argument' => $Line['text'],
					'destination' => 'elements',
				),
			),
		);
	}

	protected function paragraphContinue($Line, array $Block)
	{
		if (isset($Block['interrupted']))
		{
			return;
		}

		$Block['element']['handler']['argument'] .= "\n".$Line['text'];

		return $Block;
	}

	#
	# Inline Elements
	#

	protected $InlineTypes = array(
		'!' => array('Image'),
		'&' => array('SpecialCharacter'),
		'*' => array('Emphasis'),
		':' => array('Url'),
		'<' => array('UrlTag', 'EmailTag', 'Markup'),
		'[' => array('Link'),
		'_' => array('Emphasis'),
		'`' => array('Code'),
		'~' => array('Strikethrough'),
		'\\' => array('EscapeSequence'),
	);

	# ~

	protected $inlineMarkerList = '!*_&[:<`~\\';

	#
	# ~
	#

	public function line($text, $nonNestables = array())
	{
		return $this->elements($this->lineElements($text, $nonNestables));
	}

	protected function lineElements($text, $nonNestables = array())
	{
		$Elements = array();

		$nonNestables = (empty($nonNestables)
			? array()
			: array_combine($nonNestables, $nonNestables)
		);

		# $excerpt is based on the first occurrence of a marker

		while ($excerpt = strpbrk($text, $this->inlineMarkerList))
		{
			$marker = $excerpt[0];

			$markerPosition = strlen($text) - strlen($excerpt);

			$Excerpt = array('text' => $excerpt, 'context' => $text);

			foreach ($this->InlineTypes[$marker] as $inlineType)
			{
				# check to see if the current inline type is nestable in the current context

				if (isset($nonNestables[$inlineType]))
				{
					continue;
				}

				$Inline = $this->{"inline$inlineType"}($Excerpt);

				if ( ! isset($Inline))
				{
					continue;
				}

				# makes sure that the inline belongs to "our" marker

				if (isset($Inline['position']) and $Inline['position'] > $markerPosition)
				{
					continue;
				}

				# sets a default inline position

				if ( ! isset($Inline['position']))
				{
					$Inline['position'] = $markerPosition;
				}

				# cause the new element to 'inherit' our non nestables


				$Inline['element']['nonNestables'] = isset($Inline['element']['nonNestables'])
					? array_merge($Inline['element']['nonNestables'], $nonNestables)
					: $nonNestables
				;

				# the text that comes before the inline
				$unmarkedText = substr($text, 0, $Inline['position']);

				# compile the unmarked text
				$InlineText = $this->inlineText($unmarkedText);
				$Elements[] = $InlineText['element'];

				# compile the inline
				$Elements[] = $this->extractElement($Inline);

				# remove the examined text
				$text = substr($text, $Inline['position'] + $Inline['extent']);

				continue 2;
			}

			# the marker does not belong to an inline

			$unmarkedText = substr($text, 0, $markerPosition + 1);

			$InlineText = $this->inlineText($unmarkedText);
			$Elements[] = $InlineText['element'];

			$text = substr($text, $markerPosition + 1);
		}

		$InlineText = $this->inlineText($text);
		$Elements[] = $InlineText['element'];

		foreach ($Elements as &$Element)
		{
			if ( ! isset($Element['autobreak']))
			{
				$Element['autobreak'] = false;
			}
		}

		return $Elements;
	}

	#
	# ~
	#

	protected function inlineText($text)
	{
		$Inline = array(
			'extent' => strlen($text),
			'element' => array(),
		);

		$Inline['element']['elements'] = self::pregReplaceElements(
			$this->breaksEnabled ? '/[ ]*+\n/' : '/(?:[ ]*+\\\\|[ ]{2,}+)\n/',
			array(
				array('name' => 'br'),
				array('text' => "\n"),
			),
			$text
		);

		return $Inline;
	}

	protected function inlineCode($Excerpt)
	{
		$marker = $Excerpt['text'][0];

		if (preg_match('/^(['.$marker.']++)[ ]*+(.+?)[ ]*+(?<!['.$marker.'])\1(?!'.$marker.')/s', $Excerpt['text'], $matches))
		{
			$text = $matches[2];
			$text = preg_replace('/[ ]*+\n/', ' ', $text);

			return array(
				'extent' => strlen($matches[0]),
				'element' => array(
					'name' => 'code',
					'text' => $text,
				),
			);
		}
	}

	protected function inlineEmailTag($Excerpt)
	{
		$hostnameLabel = '[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?';

		$commonMarkEmail = '[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]++@'
			. $hostnameLabel . '(?:\.' . $hostnameLabel . ')*';

		if (strpos($Excerpt['text'], '>') !== false
			and preg_match("/^<((mailto:)?$commonMarkEmail)>/i", $Excerpt['text'], $matches)
		){
			$url = $matches[1];

			if ( ! isset($matches[2]))
			{
				$url = "mailto:$url";
			}

			return array(
				'extent' => strlen($matches[0]),
				'element' => array(
					'name' => 'a',
					'text' => $matches[1],
					'attributes' => array(
						'href' => $url,
					),
				),
			);
		}
	}

	protected function inlineEmphasis($Excerpt)
	{
		if ( ! isset($Excerpt['text'][1]))
		{
			return;
		}

		$marker = $Excerpt['text'][0];

		if ($Excerpt['text'][1] === $marker and preg_match($this->StrongRegex[$marker], $Excerpt['text'], $matches))
		{
			$emphasis = 'strong';
		}
		elseif (preg_match($this->EmRegex[$marker], $Excerpt['text'], $matches))
		{
			$emphasis = 'em';
		}
		else
		{
			return;
		}

		return array(
			'extent' => strlen($matches[0]),
			'element' => array(
				'name' => $emphasis,
				'handler' => array(
					'function' => 'lineElements',
					'argument' => $matches[1],
					'destination' => 'elements',
				)
			),
		);
	}

	protected function inlineEscapeSequence($Excerpt)
	{
		if (isset($Excerpt['text'][1]) and in_array($Excerpt['text'][1], $this->specialCharacters))
		{
			return array(
				'element' => array('rawHtml' => $Excerpt['text'][1]),
				'extent' => 2,
			);
		}
	}

	protected function inlineImage($Excerpt)
	{
		if ( ! isset($Excerpt['text'][1]) or $Excerpt['text'][1] !== '[')
		{
			return;
		}

		$Excerpt['text']= substr($Excerpt['text'], 1);

		$Link = $this->inlineLink($Excerpt);

		if ($Link === null)
		{
			return;
		}

		$Inline = array(
			'extent' => $Link['extent'] + 1,
			'element' => array(
				'name' => 'img',
				'attributes' => array(
					'src' => $Link['element']['attributes']['href'],
					'alt' => $Link['element']['handler']['argument'],
				),
				'autobreak' => true,
			),
		);

		$Inline['element']['attributes'] += $Link['element']['attributes'];

		unset($Inline['element']['attributes']['href']);

		return $Inline;
	}

	protected function inlineLink($Excerpt)
	{
		$Element = array(
			'name' => 'a',
			'handler' => array(
				'function' => 'lineElements',
				'argument' => null,
				'destination' => 'elements',
			),
			'nonNestables' => array('Url', 'Link'),
			'attributes' => array(
				'href' => null,
				'title' => null,
			),
		);

		$extent = 0;

		$remainder = $Excerpt['text'];

		if (preg_match('/\[((?:[^][]++|(?R))*+)\]/', $remainder, $matches))
		{
			$Element['handler']['argument'] = $matches[1];

			$extent += strlen($matches[0]);

			$remainder = substr($remainder, $extent);
		}
		else
		{
			return;
		}

		if (preg_match('/^[(]\s*+((?:[^ ()]++|[(][^ )]+[)])++)(?:[ ]+("[^"]*+"|\'[^\']*+\'))?\s*+[)]/', $remainder, $matches))
		{
			$Element['attributes']['href'] = $matches[1];

			if (isset($matches[2]))
			{
				$Element['attributes']['title'] = substr($matches[2], 1, - 1);
			}

			$extent += strlen($matches[0]);
		}
		else
		{
			if (preg_match('/^\s*\[(.*?)\]/', $remainder, $matches))
			{
				$definition = strlen($matches[1]) ? $matches[1] : $Element['handler']['argument'];
				$definition = strtolower($definition);

				$extent += strlen($matches[0]);
			}
			else
			{
				$definition = strtolower($Element['handler']['argument']);
			}

			if ( ! isset($this->DefinitionData['Reference'][$definition]))
			{
				return;
			}

			$Definition = $this->DefinitionData['Reference'][$definition];

			$Element['attributes']['href'] = $Definition['url'];
			$Element['attributes']['title'] = $Definition['title'];
		}

		return array(
			'extent' => $extent,
			'element' => $Element,
		);
	}

	protected function inlineMarkup($Excerpt)
	{
		if ($this->markupEscaped or $this->safeMode or strpos($Excerpt['text'], '>') === false)
		{
			return;
		}

		if ($Excerpt['text'][1] === '/' and preg_match('/^<\/\w[\w-]*+[ ]*+>/s', $Excerpt['text'], $matches))
		{
			return array(
				'element' => array('rawHtml' => $matches[0]),
				'extent' => strlen($matches[0]),
			);
		}

		if ($Excerpt['text'][1] === '!' and preg_match('/^<!---?[^>-](?:-?+[^-])*-->/s', $Excerpt['text'], $matches))
		{
			return array(
				'element' => array('rawHtml' => $matches[0]),
				'extent' => strlen($matches[0]),
			);
		}

		if ($Excerpt['text'][1] !== ' ' and preg_match('/^<\w[\w-]*+(?:[ ]*+'.$this->regexHtmlAttribute.')*+[ ]*+\/?>/s', $Excerpt['text'], $matches))
		{
			return array(
				'element' => array('rawHtml' => $matches[0]),
				'extent' => strlen($matches[0]),
			);
		}
	}

	protected function inlineSpecialCharacter($Excerpt)
	{
		if ($Excerpt['text'][1] !== ' ' and strpos($Excerpt['text'], ';') !== false
			and preg_match('/^&(#?+[0-9a-zA-Z]++);/', $Excerpt['text'], $matches)
		) {
			return array(
				'element' => array('rawHtml' => '&' . $matches[1] . ';'),
				'extent' => strlen($matches[0]),
			);
		}

		return;
	}

	protected function inlineStrikethrough($Excerpt)
	{
		if ( ! isset($Excerpt['text'][1]))
		{
			return;
		}

		if ($Excerpt['text'][1] === '~' and preg_match('/^~~(?=\S)(.+?)(?<=\S)~~/', $Excerpt['text'], $matches))
		{
			return array(
				'extent' => strlen($matches[0]),
				'element' => array(
					'name' => 'del',
					'handler' => array(
						'function' => 'lineElements',
						'argument' => $matches[1],
						'destination' => 'elements',
					)
				),
			);
		}
	}

	protected function inlineUrl($Excerpt)
	{
		if ($this->urlsLinked !== true or ! isset($Excerpt['text'][2]) or $Excerpt['text'][2] !== '/')
		{
			return;
		}

		if (strpos($Excerpt['context'], 'http') !== false
			and preg_match('/\bhttps?+:[\/]{2}[^\s<]+\b\/*+/ui', $Excerpt['context'], $matches, PREG_OFFSET_CAPTURE)
		) {
			$url = $matches[0][0];

			$Inline = array(
				'extent' => strlen($matches[0][0]),
				'position' => $matches[0][1],
				'element' => array(
					'name' => 'a',
					'text' => $url,
					'attributes' => array(
						'href' => $url,
					),
				),
			);

			return $Inline;
		}
	}

	protected function inlineUrlTag($Excerpt)
	{
		if (strpos($Excerpt['text'], '>') !== false and preg_match('/^<(\w++:\/{2}[^ >]++)>/i', $Excerpt['text'], $matches))
		{
			$url = $matches[1];

			return array(
				'extent' => strlen($matches[0]),
				'element' => array(
					'name' => 'a',
					'text' => $url,
					'attributes' => array(
						'href' => $url,
					),
				),
			);
		}
	}

	# ~

	protected function unmarkedText($text)
	{
		$Inline = $this->inlineText($text);
		return $this->element($Inline['element']);
	}

	#
	# Handlers
	#

	protected function handle(array $Element)
	{
		if (isset($Element['handler']))
		{
			if (!isset($Element['nonNestables']))
			{
				$Element['nonNestables'] = array();
			}

			if (is_string($Element['handler']))
			{
				$function = $Element['handler'];
				$argument = $Element['text'];
				unset($Element['text']);
				$destination = 'rawHtml';
			}
			else
			{
				$function = $Element['handler']['function'];
				$argument = $Element['handler']['argument'];
				$destination = $Element['handler']['destination'];
			}

			$Element[$destination] = $this->{$function}($argument, $Element['nonNestables']);

			if ($destination === 'handler')
			{
				$Element = $this->handle($Element);
			}

			unset($Element['handler']);
		}

		return $Element;
	}

	protected function handleElementRecursive(array $Element)
	{
		return $this->elementApplyRecursive(array($this, 'handle'), $Element);
	}

	protected function handleElementsRecursive(array $Elements)
	{
		return $this->elementsApplyRecursive(array($this, 'handle'), $Elements);
	}

	protected function elementApplyRecursive($closure, array $Element)
	{
		$Element = call_user_func($closure, $Element);

		if (isset($Element['elements']))
		{
			$Element['elements'] = $this->elementsApplyRecursive($closure, $Element['elements']);
		}
		elseif (isset($Element['element']))
		{
			$Element['element'] = $this->elementApplyRecursive($closure, $Element['element']);
		}

		return $Element;
	}

	protected function elementApplyRecursiveDepthFirst($closure, array $Element)
	{
		if (isset($Element['elements']))
		{
			$Element['elements'] = $this->elementsApplyRecursiveDepthFirst($closure, $Element['elements']);
		}
		elseif (isset($Element['element']))
		{
			$Element['element'] = $this->elementsApplyRecursiveDepthFirst($closure, $Element['element']);
		}

		$Element = call_user_func($closure, $Element);

		return $Element;
	}

	protected function elementsApplyRecursive($closure, array $Elements)
	{
		foreach ($Elements as &$Element)
		{
			$Element = $this->elementApplyRecursive($closure, $Element);
		}

		return $Elements;
	}

	protected function elementsApplyRecursiveDepthFirst($closure, array $Elements)
	{
		foreach ($Elements as &$Element)
		{
			$Element = $this->elementApplyRecursiveDepthFirst($closure, $Element);
		}

		return $Elements;
	}

	protected function element(array $Element)
	{
		if ($this->safeMode)
		{
			$Element = $this->sanitiseElement($Element);
		}

		# identity map if element has no handler
		$Element = $this->handle($Element);

		$hasName = isset($Element['name']);

		$markup = '';

		if ($hasName)
		{
			$markup .= '<' . $Element['name'];

			if (isset($Element['attributes']))
			{
				foreach ($Element['attributes'] as $name => $value)
				{
					if ($value === null)
					{
						continue;
					}

					$markup .= " $name=\"".self::escape($value).'"';
				}
			}
		}

		$permitRawHtml = false;

		if (isset($Element['text']))
		{
			$text = $Element['text'];
		}
		// very strongly consider an alternative if you're writing an
		// extension
		elseif (isset($Element['rawHtml']))
		{
			$text = $Element['rawHtml'];

			$allowRawHtmlInSafeMode = isset($Element['allowRawHtmlInSafeMode']) && $Element['allowRawHtmlInSafeMode'];
			$permitRawHtml = !$this->safeMode || $allowRawHtmlInSafeMode;
		}

		$hasContent = isset($text) || isset($Element['element']) || isset($Element['elements']);

		if ($hasContent)
		{
			$markup .= $hasName ? '>' : '';

			if (isset($Element['elements']))
			{
				$markup .= $this->elements($Element['elements']);
			}
			elseif (isset($Element['element']))
			{
				$markup .= $this->element($Element['element']);
			}
			else
			{
				if (!$permitRawHtml)
				{
					$markup .= self::escape($text, true);
				}
				else
				{
					$markup .= $text;
				}
			}

			$markup .= $hasName ? '</' . $Element['name'] . '>' : '';
		}
		elseif ($hasName)
		{
			$markup .= ' />';
		}

		return $markup;
	}

	protected function elements(array $Elements)
	{
		$markup = '';

		$autoBreak = true;

		foreach ($Elements as $Element)
		{
			if (empty($Element))
			{
				continue;
			}

			$autoBreakNext = (isset($Element['autobreak'])
				? $Element['autobreak'] : isset($Element['name'])
			);
			// (autobreak === false) covers both sides of an element
			$autoBreak = !$autoBreak ? $autoBreak : $autoBreakNext;

			$markup .= ($autoBreak ? "\n" : '') . $this->element($Element);
			$autoBreak = $autoBreakNext;
		}

		$markup .= $autoBreak ? "\n" : '';

		return $markup;
	}

	# ~

	protected function li($lines)
	{
		$Elements = $this->linesElements($lines);

		if ( ! in_array('', $lines)
			and isset($Elements[0]) and isset($Elements[0]['name'])
			and $Elements[0]['name'] === 'p'
		) {
			unset($Elements[0]['name']);
		}

		return $Elements;
	}

	#
	# AST Convenience
	#

	/**
	 * Replace occurrences $regexp with $Elements in $text. Return an array of
	 * elements representing the replacement.
	 */
	protected static function pregReplaceElements($regexp, $Elements, $text)
	{
		$newElements = array();

		while (preg_match($regexp, $text, $matches, PREG_OFFSET_CAPTURE))
		{
			$offset = $matches[0][1];
			$before = substr($text, 0, $offset);
			$after = substr($text, $offset + strlen($matches[0][0]));

			$newElements[] = array('text' => $before);

			foreach ($Elements as $Element)
			{
				$newElements[] = $Element;
			}

			$text = $after;
		}

		$newElements[] = array('text' => $text);

		return $newElements;
	}

	#
	# Deprecated Methods
	#

	function parse($text)
	{
		$markup = $this->text($text);

		return $markup;
	}

	protected function sanitiseElement(array $Element)
	{
		static $goodAttribute = '/^[a-zA-Z0-9][a-zA-Z0-9-_]*+$/';
		static $safeUrlNameToAtt  = array(
			'a'   => 'href',
			'img' => 'src',
		);

		if ( ! isset($Element['name']))
		{
			unset($Element['attributes']);
			return $Element;
		}

		if (isset($safeUrlNameToAtt[$Element['name']]))
		{
			$Element = $this->filterUnsafeUrlInAttribute($Element, $safeUrlNameToAtt[$Element['name']]);
		}

		if ( ! empty($Element['attributes']))
		{
			foreach ($Element['attributes'] as $att => $val)
			{
				# filter out badly parsed attribute
				if ( ! preg_match($goodAttribute, $att))
				{
					unset($Element['attributes'][$att]);
				}
				# dump onevent attribute
				elseif (self::striAtStart($att, 'on'))
				{
					unset($Element['attributes'][$att]);
				}
			}
		}

		return $Element;
	}

	protected function filterUnsafeUrlInAttribute(array $Element, $attribute)
	{
		foreach ($this->safeLinksWhitelist as $scheme)
		{
			if (self::striAtStart($Element['attributes'][$attribute], $scheme))
			{
				return $Element;
			}
		}

		$Element['attributes'][$attribute] = str_replace(':', '%3A', $Element['attributes'][$attribute]);

		return $Element;
	}

	#
	# Static Methods
	#

	protected static function escape($text, $allowQuotes = false)
	{
		return htmlspecialchars($text, $allowQuotes ? ENT_NOQUOTES : ENT_QUOTES, 'UTF-8');
	}

	protected static function striAtStart($string, $needle)
	{
		$len = strlen($needle);

		if ($len > strlen($string))
		{
			return false;
		}
		else
		{
			return strtolower(substr($string, 0, $len)) === strtolower($needle);
		}
	}

	static function instance($name = 'default')
	{
		if (isset(self::$instances[$name]))
		{
			return self::$instances[$name];
		}

		$instance = new static();

		self::$instances[$name] = $instance;

		return $instance;
	}

	private static $instances = array();

	#
	# Fields
	#

	protected $DefinitionData;

	#
	# Read-Only

	protected $specialCharacters = array(
		'\\', '`', '*', '_', '{', '}', '[', ']', '(', ')', '>', '#', '+', '-', '.', '!', '|', '~'
	);

	protected $StrongRegex = array(
		'*' => '/^[*]{2}((?:\\\\\*|[^*]|[*][^*]*+[*])+?)[*]{2}(?![*])/s',
		'_' => '/^__((?:\\\\_|[^_]|_[^_]*+_)+?)__(?!_)/us',
	);

	protected $EmRegex = array(
		'*' => '/^[*]((?:\\\\\*|[^*]|[*][*][^*]+?[*][*])+?)[*](?![*])/s',
		'_' => '/^_((?:\\\\_|[^_]|__[^_]*__)+?)_(?!_)\b/us',
	);

	protected $regexHtmlAttribute = '[a-zA-Z_:][\w:.-]*+(?:\s*+=\s*+(?:[^"\'=<>`\s]+|"[^"]*+"|\'[^\']*+\'))?+';

	protected $voidElements = array(
		'area', 'base', 'br', 'col', 'command', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source',
	);

	protected $textLevelElements = array(
		'a', 'br', 'bdo', 'abbr', 'blink', 'nextid', 'acronym', 'basefont',
		'b', 'em', 'big', 'cite', 'small', 'spacer', 'listing',
		'i', 'rp', 'del', 'code',		  'strike', 'marquee',
		'q', 'rt', 'ins', 'font',		  'strong',
		's', 'tt', 'kbd', 'mark',
		'u', 'xm', 'sub', 'nobr',
				   'sup', 'ruby',
				   'var', 'span',
				   'wbr', 'time',
	);
}
