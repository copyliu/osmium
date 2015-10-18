<?php
/* Osmium
 * Copyright (C) 2014, 2015 Romain "Artefact2" Dalmaso <artefact2@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace Osmium\DOM;



/* A full-blown page, with the base UI. */
class Page extends RawPage {

	use Formatter;

	/* The title of this page. */
	public $title = 'Osmium page';

	/* Content-Security-Policy (CSP) rules. https?:// variants will be
	 * automatically added if using TLS. */
	public $csp = [
		'default-src' => [ "'none'" ],
		'style-src' => [
			"'self'",
			"'unsafe-inline'",
			'//'.\Osmium\GOOGLE_FONT_API,
			'//'.\Osmium\CLOUDFLARE_JSCDN,
		],
		'font-src' => [ '//'.\Osmium\GOOGLE_STATIC ],
		'img-src' => [ "'self'", '//'.\Osmium\EVE_IEC, '//'.\Osmium\EVE_IMG ],
		'script-src' => [ "'self'", '//'.\Osmium\CLOUDFLARE_JSCDN ],
		'connect-src' => [ "'self'" ],
	];

	/* An array of Javascript snippets to include in the page. Paths
	 * start from /src/snippets/. Don't include the ".js" suffix
	 * either. */
	public $snippets = [];

	/* An array of data to pass as data-* attributes, to share data
	 * with Javascript snippets without using an (unsafe) inline
	 * script. Values will be JSON-encoded. */
	public $data = [];



	/* Decides whether robots can index this page or not. */
	public $index = true;

	/* The canonical URI of this page. Assume / is Osmium
	 * root. (Example $canonical: '/loadout/foo') */
	public $canonical = null;

	/* The theme to use. Must be a key of the array $_themes (see
	 * below), or the value 'auto' to guess from cookie or use the
	 * default. */
	public $theme = 'auto';



	/* The root <html> element. */
	public $html;

	/* The <head> element. */
	public $head;

	/* The <body> element. */
	public $body;

	/* The wrapper <div> inside <body>. Append the page's content to
	 * this element. */
	public $content;



	/* List of available themes. */
	private static $_themes = [
		'Dark' => 'dark.css',
		'Light' => 'light.css',
	];



	/* Compiles the specified snippets and adds the relevant <script>s to the page body. */
	private function renderScripts(RenderContext $ctx) {
		if($this->snippets === []) return;

		/* Like array_unique(), but O(n) */
		$snippets = array_flip(array_flip($this->snippets));

		$name = 'js.'.implode('.', ($snippets === [ 'common' ] ? $snippets : array_slice($snippets, 1)));
		$cacheminfile = \Osmium\ROOT.'/static'.($finaluri = '/cache/'.$name.'.min.js');
		$meta = \Osmium\State\get_cache_memory_fb($metaname = $name.'.meta', null);

		if(!file_exists($cacheminfile) || $meta === null) {
			$sem = \Osmium\State\semaphore_acquire('snippet.'.$name);

			/* Maybe another process already made the file while
			 * waiting for the semaphore? */
			clearstatcache(true, $cacheminfile);
			if($hassnippet = file_exists($cacheminfile)) {
				$meta = \Osmium\State\get_cache_memory_fb($metaname, null);
			}

			if(!$hassnippet || $meta === null) {
				/* This is a fairly expensive operation (especially if the
				 * output is piped in a minifier), hence the
				 * semaphores. */
				$this->compileSnippets(
					$ctx,
					$snippets,
					\Osmium\ROOT.'/static/cache/'.$name.'.js',
					$cacheminfile,
					$meta /* Passed by reference */
				);
				\Osmium\State\put_cache_memory_fb($metaname, $meta, 86400);
			}

			\Osmium\State\semaphore_release($sem);
		}

		if(isset($meta['head']) && $meta['head'] !== '') {
			$this->head->append($this->fragment($meta['head']));
		}
		if(isset($meta['before']) && $meta['before'] !== '') {
			$this->body->append($this->fragment($meta['before']));
		}
		$this->body->appendCreate('script', [
			'type' => 'application/javascript',
			'id' => 'snippets',
			'o-static-js-src' => $finaluri,
		]);
		if(isset($meta['after']) && $meta['after'] !== '') {
			$this->body->append($this->fragment($meta['after']));
		}
	}

	/* @internal */
	private function compileSnippets(RenderContext $ctx, array $snippets, $out, $minout, &$meta = []) {
		$hasit = [];
		$meta['before'] = '';
		$meta['after'] = '';
		$meta['head'] = '';
		$c = count($snippets);

		do {
			$added = 0;

			for($i = 0; $i < $c; ++$i) {
				$s = $snippets[$i];
				if(isset($hasit[$s])) continue;
				$hasit[$s] = true;

				if(!file_exists($sfile = \Osmium\ROOT.'/src/snippets/'.$s.'.js')) {
					throw new \Exception("snippet $s does not exist");
				}

				preg_match_all(
					'%^/\*<<<\s+require\s+(?<type>snippet|external|css)\s+(?<src>.+?)(\s+(?<position>first|last|before|after|anywhere))?\s+>>>\*/$%m',
					file_get_contents($sfile),
					$matches,
					\PREG_SET_ORDER
				);

				foreach($matches as $m) {
					if(!isset($m['position']) || $m['position'] === '') $m['position'] = 'anywhere';

					if(isset($hasit[$m['src']])) {
						if($m['type'] !== 'snippet') {
							/* TODO */
							continue;
						}

						/* Dependency already included, check order consistency */

						$pos = array_search($m['src'], $snippets, true);
						if((($m['position'] === 'first' || $m['position'] === 'before') && $pos >= $i)
						   || (($m['position'] === 'last' || $m['position'] === 'after') && $pos <= $i)) {
							throw new \Exception(
								"snippet $s($i) requires {$m['src']}($pos) {$m['position']} but order is inconsistent"
							);
						}

						continue;
					}

					if($m['type'] === 'external' || $m['type'] === 'css') {
						$hasit[$m['src']] = true;

						$relative = substr($m['src'], 0, 2) !== '//'
							&& substr($m['src'], 0, 7) !== 'http://'
							&& substr($m['src'], 0, 8) !== 'https://'
							;

					    if($m['type'] === 'css') {
						    $xml = $this->element('link', [
							    'rel' => 'stylesheet',
							    'type' => 'text/css',
						    ]);

						    $xml->setAttribute(
							    $relative ? 'o-rel-href' : 'href',
							    $m['src']
						    );

						    $xml = $xml->renderNode();
						    $before =& $meta['head'];
						    $after =& $meta['head'];
					    } else if($m['type'] === 'external') {
						    $xml = $this->element('script', [
							    'type' => 'application/javascript',
						    ]);

						    $xml->setAttribute(
							    $relative ? 'o-rel-src' : 'src',
							    $m['src']
						    );

						    $xml = $xml->renderNode();
						    $before =& $meta['before'];
						    $after =& $meta['after'];
					    }

						switch($m['position']) {

						case 'before':
						case 'anywhere':
							$before .= $xml;
							break;

						case 'after':
							$after = $xml.$after;
							break;

						case 'first':
							$before = $xml.$before;
							break;

						case 'last':
							$after .= $xml;
							break;

						}

						unset($before);
						unset($after);

						continue;
					}

					/* Insert the dependency */
					++$added;
					++$c;

					switch($m['position']) {

					case 'before':
					case 'anywhere':
						array_splice($snippets, $i, 0, $m['src']);
						++$i;
						break;

					case 'after':
						array_splice($snippets, $i + 1, 0, $m['src']);
						break;

					case 'first':
						array_unshift($snippets, $m['src']);
						++$i;
						break;

					case 'last':
						array_push($snippets, $m['src']);
						break;

					}
				}
			}
		} while($added > 0);

		$snippets = implode(' ', array_map(function($s) {
			return escapeshellarg(\Osmium\ROOT.'/src/snippets/'.$s.'.js');
		}, $snippets));
		$ecf = escapeshellarg($out);
		$ecmf = escapeshellarg($minout);

		if (\Osmium\get_ini_setting("serenity_patch")){
			$sed_command = 'sed "s/image\\.eveonline\\.com/'.\Osmium\EVE_IEC.'/g"';
			shell_exec($cmd = 'cat '.$snippets.' | '.$sed_command.' > '.$ecf);
		}
		else{
			shell_exec($cmd = 'cat '.$snippets.' > '.$ecf);
		}
		if($min = \Osmium\get_ini_setting('minify_js')) {
			$command = \Osmium\get_ini_setting('minify_command');

			/* Concatenate & minify */
			shell_exec('cat '.$ecf.' | '.$command.' > '.$ecmf);
		}

		clearstatcache(true, $minout);
		if(!$min || !file_exists($minout)) {
			/* Not minifying, or minifier failed for some reason */
			shell_exec('ln -s '.$ecf.' '.$ecmf);
		}
	}



	function __construct() {
		parent::__construct();

		$this->html = $this->element('html');
		$this->head = $this->html->appendCreate('head');
		$this->body = $this->html->appendCreate('body');
		$this->content = $this->body->appendCreate('div', [ 'id' => 'wrapper' ]);

		$this->appendChild($this->html);
	}



	/* @see RawPage::finalize() */
	function finalize(RenderContext $ctx) {
		if($this->finalized) return;

		$this->head->appendCreate('title', $this->title.' / '.\Osmium\get_ini_setting('name'));

		/* Don't ever risk leaking a private URI via referrers. */
		$this->head->appendCreate('meta', [ 'name' => 'referrer', 'content' => 'origin' ]);

		if(!$this->index) {
			$this->head->appendCreate('meta', [ 'name' => 'robots', 'content' => 'noindex' ]);
		} else {
			if($this->canonical !== null) {
				$this->head->appendCreate('link', [
					'rel' => 'canonical',
					'href' => \Osmium\get_absolute_root().$this->canonical,
				]);
			}
		}

		$this->head->appendCreate('link', [
			'rel' => 'help',
			'o-rel-href' => '/help',
		]);

		$flink = $this->head->appendCreate('link', [
			'rel' => 'icon',
			'type' => 'image/png'
		]);

		$favicon = \Osmium\get_ini_setting('favicon');
		if(substr($favicon, 0, 2) === '//') {
			/* Absolute URI */
			$flink->attr('href', $favicon);
		} else {
			/* Relative, in static/ */
			$flink->attr('o-static-href', '/'.$favicon);
		}

		array_unshift($this->snippets, 'common');

		$this->data['relative'] = $ctx->relative;

		$this->renderThemes();
		$this->renderHeader();
		$this->renderFooter();
		$this->renderScripts($ctx);

		parent::finalize($ctx);
	}

	/* Render this page. Assumes headers have not been sent yet. */
	function render(RenderContext $ctx) {
		$this->finalize($ctx);

		$csp = $this->csp;
		foreach($csp as $k => $rules) {
			$processedrules = [ $k ];
			foreach($rules as $r) {
				if(substr($r, 0, 2) === '//') {
					$processedrules[] = 'https:'.$r;
					if(!\Osmium\HTTPS) {
						$processedrules[] = 'http:'.$r;
					}
				} else {
					$processedrules[] = $r;
				}
			}

			$csp[$k] = implode(' ', $processedrules);
		}
		header('Content-Security-Policy: '.implode(' ; ', $csp));

		if(\Osmium\HTTPS
		   && \Osmium\get_ini_setting('https_available')
		   && \Osmium\get_ini_setting('use_hsts')) {
			$maxage = (int)\Osmium\get_ini_setting('https_cert_expiration') - time() - 86400;
			if($maxage > 0) {
				header('Strict-Transport-Security: max-age='.$maxage);
			}
		}

		$this->appendChild($this->createComment(' '.(microtime(true) - \Osmium\T0).' '));

		if($ctx->xhtml) {
			header('Content-Type: application/xhtml+xml');
			$this->html->setAttribute('xmlns', 'http://www.w3.org/1999/xhtml');

			$this->save('php://output');
		} else {
			header('Content-Type: text/html; charset=utf-8');
			$this->head->prepend($this->element('meta', [ 'charset' => 'UTF-8' ]));

			echo "<!DOCTYPE html>\n";
			$this->saveHTMLFile('php://output');
		}

		flush(); /* Not sure about its effectiveness, but it doesn't hurt */

		\Osmium\State\put_activity();
	}



	/* Make a link to an account, using the account nickname or
	 * character name appropriately.
	 *
	 * @returns an element. The chosen name (either character or nick)
	 * will be put in $chosenname if present.
	 */
	function makeAccountLink(array $a, &$chosenname = null) {
		if(!isset($a['accountid']) || !($a['accountid'] > 0)) {
			return $this->createTextNode('N/A');
		}

		$span = $this->element('span');

		if(isset($a['apiverified']) && $a['apiverified'] === 't'
		   && isset($a['characterid']) && $a['characterid'] > 0) {
			$span->addClass('apiverified');
			$span->append($chosenname = $a['charactername']);
		} else {
			$span->addClass('normalaccount');
			$span->append($chosenname = $a['nickname']);
		}

		if(isset($a['ismoderator']) && $a['ismoderator'] === 't') {
			$span = $this->element('span', [
				'title' => 'Moderator', 'class' => 'mod',
				\Osmium\Flag\MODERATOR_SYMBOL,
				$span
			]);
		}

		return $this->element('a', [
			'class' => 'profile',
			'o-rel-href' => '/profile/'.$a['accountid'],
			$span,
		]);
	}



	/* For use in makeSearchBox(). */
	const MSB_SEARCH = 1; /* Loadouts and types */
	const MSB_FILTER = 2;
	const MSB_SEARCH_TYPES = 3; /* Search only types */

	/* Make a search box. */
	function makeSearchBox($mode = self::MSB_SEARCH) {
		static $idx = -1;
		++$idx;

		static $examples = [
			"@ship Drake | Tengu @tags missile-boat",
			"@shipgroup Cruiser -Strategic -Heavy @dps >= 500",
			"@tags -armor-tank",
			"@dps >= 400 @ehp >= 40k @tags pvp",
			"battlecruiser @types \"stasis webifier\"",
			"@tags cheap low-sp @estimatedprice <= 10m",
			"battleship @tags pve|l4|missions",
		];

		$f = $this->element('o-form', [ 'method' => 'get' ]);

		if($mode !== self::MSB_FILTER) {
			$f->setAttribute('o-rel-action', '/search');
		} else {
			$f->setAttribute('action', '');
		}

		$sprite = $this->element('o-sprite', [
			'x' => $mode === self::MSB_FILTER ? 3 : 2,
			'y' => 12,
			'gridwidth' => 64,
			'gridheight' => 64,
			'alt' => '',
		]);

		$label = $f->appendCreate('h1')->appendCreate('label', [
			'for' => 'search'.$idx,
			$sprite,
		]);

		$p = $f->appendCreate('p');
		$p->append([
			[ 'o-input', [
				'id' => 'search'.$idx,
				'type' => 'search',
				'name' => 'q',
				'placeholder' => $mode !== self::MSB_SEARCH_TYPES ?
				$examples[mt_rand(0, count($examples) - 1)]
				: 'type name, group name or abbreviation…',
			]],
			[ 'input', [
				'type' => 'submit',
				'value' => 'Go!',
			]],
			[ 'br' ],
		]);

		if($mode === self::MSB_SEARCH_TYPES) {
			$label->append('Search types');
			$label->attr('data-i18n','main:search_types');
			$p->appendCreate('input', [
				'type' => 'hidden',
				'name' => 'm',
				'value' => 't',
			]);
			
			return $f;
		}

		if(isset($_GET['ad']) && $_GET['ad'] === '1') {
			/* Advanced search mode */
			$label->append('Advanced search');
			$label->attr('data-i18n','main:search_adv');
			$sbuild = $this->element('o-select', [ 'name' => 'build' ]);
			foreach(\Osmium\Fit\get_eve_db_versions() as $v) {
				$sbuild->appendCreate('option', [ 'value' => $v['build'], $v['name'] ]);
			}

			$soperand = $this->element('o-select', [ 'name' => 'op' ]);
			foreach(\Osmium\Search\get_operator_list() as $op => $label) {
				$soperand->appendCreate('option', [ 'value' => $op, $label[1] ]);
			}

			$ssort = $this->element('o-select', [ 'name' => 'sort' ]);
			foreach(\Osmium\Search\get_orderby_list() as $sort => $label) {
				$ssort->appendCreate('option', [ 'value' => $sort, $label ]);
			}

			$sorder = $this->element('o-select', [ 'name' => 'order' ]);
			foreach(\Osmium\Search\get_order_list() as $sort => $label) {
				$sorder->appendCreate('option', [ 'value' => $sort, $label ]);
			}

			$p->append([
				'for ', $sbuild, ' ', $soperand, [ 'br' ],
				'sort by ', $ssort, ' ', $sorder,
			]);

			$availskillsets = \Osmium\Fit\get_available_skillset_names_for_account();
			if(count($availskillsets) > 2) {
				$sskillsets = $this->element('o-select', [ 'name' => 'ss', 'id' => 'ss' ]);
				foreach($availskillsets as $ss) {
					if($ss === 'All 0' || $ss === 'All V') continue;
					$sskillsets->appendCreate('option', [ 'value' => $ss, $ss ]);
				}

				$p->append([
					[ 'br' ],
					[ 'o-input', [ 'type' => 'checkbox', 'name' => 'sr', 'id' => 'sr' ] ],
					[ 'label', [ 'for' => 'sr', ' Only show loadouts ' ] ],
					$sskillsets,
					[ 'label', [ 'for' => 'sr', ' can fly' ] ],
				]);

				$this->snippets[] = 'searchform';
			}

			$vtypes = $this->element('o-select', [ 'name' => 'vrs', 'id' => 'vrs' ]);
			foreach([ 'private', 'corporation', 'alliance', 'public' ] as $t) {
				$vtypes->appendCreate('option', [ 'value' => $t, $t ]);
			}

			$p->append([
				[ 'br' ],
				[ 'o-input', [ 'type' => 'checkbox', 'name' => 'vr', 'id' => 'vr' ] ],
				[ 'label', [ 'for' => 'vr', ' only show ' ] ],
				$vtypes,
				[ 'label', [ 'for' => 'vr', ' loadouts' ] ],
			]);

			$p->append([
				[ 'input', [ 'type' => 'hidden', 'name' => 'ad', 'value' => 1 ] ], [ 'br' ],
				[ 'a', [ 'o-rel-href' => '/help/search', [ 'small', 'Help' ] ] ],
			]);

		} else {
			/* Simple search mode, show a link to advanced mode and nothing else */
			$label->append(($mode === self::MSB_SEARCH ? 'Search' : 'Filter').' loadouts');
			$label->attr('data-i18n','main:'.($mode === self::MSB_SEARCH ? 'search' : 'filter').'_loadouts');
			/* XXX: get_search_cond_from_advanced() will set some
			 * default values in $_GET. Ugly side effect, get rid of
			 * this ASAP. */
			\Osmium\Search\get_search_cond_from_advanced();

			$p->appendCreate('small', [
				[ 'a', [ 'href' => self::formatQueryString($_GET, [ 'ad' => 1 ]),
						 'Advanced '.($mode === self::MSB_SEARCH ? 'search' : 'filters') ] ],
				' — ',
				[ 'a', [ 'o-rel-href' => '/help/search', 'Help' ] ],
			]);
		}

		return $f;
	}


	/* Make a simple row from some nodes. */
	public function makeFormRawRow($thinside = [], $tdinside = []) {
		$tr = $this->createElement('tr');
		$tr->appendCreate('th')->append($thinside);
		$tr->appendCreate('td')->append($tdinside);
		return $tr;
	}

	/* Make a simple row with a label and an input field. */
	public function makeFormInputRow($type, $name, $label) {
		$tr = $this->createElement('tr');
		$tr->appendCreate('th')->appendCreate('label', [ 'for' => $name ])->append($label);
		$tr->appendCreate('td')->appendCreate('o-input', [
			'type' => $type,
			'name' => $name,
			'id' => $name,
		]);

		return $tr;
	}

	/* Make a simple separator row for tabular forms. */
	public function makeFormSeparatorRow() {
		$tr = $this->createElement('tr');
		$tr->setAttribute('class', 'separator');
		$tr->appendCreate('td', [ 'colspan' => '2' ])->appendCreate('hr');
		return $tr;
	}

	/* Make a simple row with a submit button for tabular forms. */
	public function makeFormSubmitRow($label) {
		return $this->makeFormRawRow([], [
			[ 'input', [ 'type' => 'submit', 'value' => $label ] ],
		]);
	}



	/**
	 * Generate pagination links and get the offset of the current page.
	 *
	 * @param $wrap insert pagination links before/after this element.
	 * @param array $opts an array of options.
	 * @param integer $total the total number of elements.
	 *
	 * @return [ offset of the current page, <p> with meta info, <ol>
	 * of pagination links ]
	 */
	public function makePagination($total, array $opts = []) {
		$opts += [
			/* Name of the $_GET parameter for the page number */
			'name' => 'p',

			/* Number of elements per page */
			'perpage' => 50,

			/* Override the current page number */
			'pageoverride' => false,

			/* Format of the shown text indicating the position within the result set */
			'format' => 'Showing rows %1-%2 of %3.',

			/* Override the shown total */
			'ftotal' => $this->formatExactInteger($total),

			/* Append this to generated link URIs */
			'anchor' => '',

			/* Add <link rel='next'> <link rel='prev'> to <head> when appropriate. */
			'addlinks' => true,

			/* Append (page X) to the page's title when appropriate. */
			'appendtitle' => true,
		];

		$name = $opts['name'];
		$page = $opts['pageoverride'] !== false ? $opts['pageoverride'] :
			(isset($_GET[$name]) ? $_GET[$name] : 1);
		$maxpage = max(1, ceil($total / $opts['perpage']));

		if($page < 1) $page = 1;
		if($page > $maxpage) $page = $maxpage;

		$offset = ($page - 1) * $opts['perpage'];
		$max = min($total, $offset + $opts['perpage']);

		$replacement = ($total > 0) ? [
			$this->formatExactInteger($offset + 1),
			$this->formatExactInteger($max),
			$opts['ftotal']
		] : [ 0, 0, 0 ];
		$p = $this->element('p.pagination', str_replace(
			[ '%1', '%2', '%3' ],
			$replacement,
			$opts['format']
		));

		if($maxpage == 1) {
			return [ $offset, '', '' ];
		}

		$ol = $this->element('ol.pagination');
		$inf = max(1, $page - 5);
		$sup = min($maxpage, $page + 4);
		$params = $_GET;

		if($opts['appendtitle'] && $page > 1) {
			$this->title .= ' / Page '.$this->formatExactInteger($page);
		}

		$first = $ol->appendCreate('li.first', [ 'value' => 1 ]);
		$prev = $ol->appendCreate('li.prev', [ 'value' => $page - 1 ]);
		if($page > 1) {
			$params[$name] = $page - 1;
			$uri = $this->formatQueryString($params).$opts['anchor'];

			if($opts['addlinks']) {
				$this->head->appendCreate('link', [
					'rel' => 'prev',
					'href' => $uri,
				]);
			}
			$prev->appendCreate('a', [ 'title' => 'go to previous page', 'href' => $uri, '⇦' ]);

			$params[$name] = 1;
			$first->appendCreate('a', [
				'href' => $this->formatQueryString($params).$opts['anchor'],
				'title' => 'go to first page',
				'⇤',
			]);
		} else {
			$prev->addClass('dummy')->appendCreate('span', '⇦');
			$first->addClass('dummy')->appendCreate('span', '⇤');
		}

		for($i = $inf; $i <= $sup; ++$i) {
			$li = $ol->appendCreate('li', [ 'value' => $i ]);

			if($i == $page) {
				$li->addClass('current')->appendCreate('span', $this->formatExactInteger($i));
				continue;
			}

			$params[$name] = $i;
			$fi = $this->formatExactInteger($i);
			$li->appendCreate('a', [
				'title' => 'go to page '.$fi,
				'href' => $this->formatQueryString($params).$opts['anchor'],
				$fi,
			]);
		}

		$next = $ol->appendCreate('li.next', [ 'value' => $page + 1 ]);
		$last = $ol->appendCreate('li.last', [ 'value' => $maxpage ]);
		if($page < $maxpage) {
			$params[$name] = $page + 1;
			$uri = $this->formatQueryString($params).$opts['anchor'];

			if($opts['addlinks']) {
				$this->head->appendCreate('link', [
					'rel' => 'next',
					'href' => $uri,
				]);
			}
			$next->appendCreate('a', [ 'title' => 'go to next page', 'href' => $uri, '⇨' ]);

			$params[$name] = $maxpage;
			$last->appendCreate('a', [
				'href' => $this->formatQueryString($params).$opts['anchor'],
				'title' => 'go to last page',
				'⇥',
			]);
		} else {
			$next->addClass('dummy')->appendCreate('span', '⇨');
			$last->addClass('dummy')->appendCreate('span', '⇥');
		}

		return [ $offset, $p, $ol ];
	}



	/* @internal */
	private function renderThemes() {
		if($this->theme === 'auto') {
			$curtheme = isset($_COOKIE['t']) && isset(self::$_themes[$_COOKIE['t']])
				? $_COOKIE['t'] : 'Dark';
		} else {
			$curtheme = $this->theme;
		}

		$this->head->appendCreate('link', [
			'rel' => 'stylesheet',
			'title' => $curtheme,
			'type' => 'text/css',
			'o-static-css-href' => '/'.self::$_themes[$curtheme],
		]);
		foreach(self::$_themes as $tn => $turi) {
			if($tn === $curtheme) continue;

			$this->head->appendCreate('link', [
				'rel' => 'alternate stylesheet',
				'title' => $tn,
				'type' => 'text/css',
				'o-static-css-href' => '/'.$turi
			]);
		}

		$this->head->appendCreate('link', [
			'rel' => 'stylesheet',
			'type' => 'text/css',
			'href' => '//'.\Osmium\GOOGLE_FONT_API.'/css?family=Droid+Serif:400,400italic,700,700italic|Droid+Sans:400,700|Droid+Sans+Mono',
		]);
	}



	/* @internal */
	private function renderHeader() {
		$nav = $this->element('nav');
		$this->content->prepend($nav);
		$nav->append($this->makeStateBox());

		$form = $nav->appendCreate('form', [
			'class' => 's',
			'method' => 'get',
			'o-rel-action' => '/search',
		]);
		$form->appendCreate('input', [
			'type' => 'search',
			'placeholder' => 'Search [s]',
			'name' => 'q',
			'accesskey' => 's',
			'title' => 'Search fittings or types',
		]);
		$form->appendCreate('input', [ 'type' => 'submit', 'value' => 'Go!' ]);

		$osmium = \Osmium\get_ini_setting('name');
		$ul = $nav->appendCreate('ul');
		$ul->append([
			$this->makeNavigationLink('/', $osmium, $osmium, 'Go to the home page', 'home'),
			$this->makeNavigationLink('/new#browse', 'Create loadout', 'Create', 'Create a new fitting', 'create'),
			$this->makeNavigationLink('/import', 'Import', 'Import',
			                          'Import one or more fittings from various formats' ,'import'),
			$this->makeNavigationLink('/convert', 'Convert', 'Convert',
			                          'Quickly convert fittings from one format to another', 'convert'),
			$this->makeNavigationLink('/browse/best', 'Browse loadouts', 'Loadouts',
			                          'Browse the loadouts most rated by the community', 'browse'),
			$this->makeNavigationLink('/db', 'Browse types', 'Types',
			                          'Browse and compare types (items) from the game database', 'type'),
		]);

		if(\Osmium\State\is_logged_in()) {
			$ul->append($this->makeNavigationLink('/settings', 'Settings', $i18ntag='setting'));

			$a = \Osmium\State\get_state('a');
			if(isset($a['ismoderator']) && $a['ismoderator'] === 't') {
				$ul->append($this->makeNavigationLink(
					'/moderation',
					\Osmium\Flag\MODERATOR_SYMBOL.'Moderation',
					\Osmium\Flag\MODERATOR_SYMBOL
				),$i18ntag="moderation");
			}
		}
	}



	/* @internal */
	private function makeNavigationLink($dest, $label, $shortlabel = null, $title = null, $i18ntag = null) {
		static $current = null;
		if($current === null) {
			$current = explode('?', $_SERVER['REQUEST_URI'], 2)[0];
		}

		if($shortlabel === null) $shortlabel = $label;

		$full = $this->element('span', [ 'class' => 'full',  $label ]);
		$mini = $this->element('span', [ 'class' => 'mini', $shortlabel ]);
		if ($i18ntag !== null){
			$full->attr('data-i18n', 'nav:'.$i18ntag.".full");
			$mini->attr('data-i18n', 'nav:'.$i18ntag.".mini");
		}

		if($title !== null) {
			$full->attr('title', $title);
			$mini->attr('title', $label.' – '.$title);
		} else {
			$mini->attr('title', $label);
		}
		
		$a = $this->element('a', [ 'o-rel-href' => $dest, $full, $mini ]);
		if(substr($current, -strlen($dest)) === $dest) {
			$a = [ 'strong', $a ];
		}

		return $this->element('li', [ $a ]);
	}



	/* @internal */
	private function makeStateBox() {
		$div = $this->element('div', [ 'id' => 'state_box' ]);

		if(\Osmium\State\is_logged_in()) {
			$div->addClass('logout');
			$p = $div->appendCreate('p');

			$a = \Osmium\State\get_state('a');

			if(isset($a['apiverified']) && $a['apiverified'] ===  't'
			   && isset($a['characterid']) && $a['characterid'] > 0) {
				$portrait = [ 'o-eve-img', [
					'src' => '/Character/'.$a['characterid'].'_128.jpg',
					'alt' => '',
					'class' => 'portrait',
				]];
			} else {
				$portrait = '';
			}

			$ncount = \Osmium\Notification\get_new_notification_count();
			if($ncount > 0) {
				$this->head->getElementsByTagName('title')->item(0)->prepend('('.$ncount.') ');
			}

			$p->append([
				$portrait,
				' ',
				[ 'strong', $this->makeAccountLink($a) ],
				' (',
				[ 'a', [
					'class' => 'rep', 'o-rel-href' => '/privileges',
					$this->formatReputation(\Osmium\Reputation\get_current_reputation()),
				]],
				'). ',
				[ 'a', [
					'id' => 'ncount',
					'data-count' => (string)$ncount,
					'o-rel-href' => '/notifications',
					'title' => $ncount.' new notification(s)',
					(string)$ncount
				]],
				' ',
				[ 'o-state-altering-a', [ 'o-rel-href' => '/internal/logout', 'data-i18n'=>'nav:signout', 'Sign out' ] ],
				' ',
				[ 'small', [
					'(',
					[ 'o-state-altering-a', [
						'o-rel-href' => '/internal/logout'.self::formatQueryString([ 'global' => '1' ]),
						'title' => 'Terminate all my sessions, even on other computers or browsers',
						'all', 'data-i18n'=>'nav:signoutall'
					]],
					')',
				]],
			]);

		} else {
			$div->addClass('login');
			$p = $div->appendCreate('p');
			$p->appendCreate('a', [ 'o-rel-href' => '/login'.$this->formatQueryString([
				'r' => $_SERVER['REQUEST_URI'],
			] ),'data-i18n' => 'nav:signin', 'Sign in' ]);

			if(\Osmium\get_ini_setting('registration_enabled')) {
				$reglink = [ 'a', [ 'o-rel-href' => '/register', 'data-i18n' => 'nav:signup',[ 'strong', 'Sign up' ] ] ];
				$p->append([ ' or ', $reglink ]);
			}
		}

		return $div;
	}



	/* @internal */
	private function renderFooter() {
		$this->content->appendCreate('div', [ 'id' => 'push' ]);
		$footer = $this->body->appendCreate('footer');
		$p = $footer->appendCreate('p');
		$p->append([
			[ 'a', [ 'o-rel-href' => '/changelog', [ 'code', [ \Osmium\get_osmium_version() ] ] ] ],
			' – ',
			[ 'a', [ 'o-rel-href' => '/about', 'rel' => 'jslicense', 'About' ] ],
			' – ',
		    [ 'a', [ 'o-rel-href' => '/help', 'rel' => 'help', 'Help' ] ],
		]);

		$datadiv = $this->body->appendCreate('div', [ 'id' => 'osmium-data' ]);
		foreach($this->data as $k => $v) {
			$datadiv->setAttribute('data-'.$k, is_string($v) ? $v : json_encode($v));
		}
	}
}
