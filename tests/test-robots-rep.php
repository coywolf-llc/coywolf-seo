<?php
/**
 * Conformance tests for the full REP evaluator (Coywolf_SEO_Robots_Rep) — the
 * PHP port of Google's robotstxt library. Every assertion here is ported from
 * Google's robots_test.cc (https://github.com/google/robotstxt). Runs without
 * WordPress:
 *
 *     php tests/test-robots-rep.php
 *
 * Exits non-zero on any failure. Excluded from the distributed plugin zip.
 *
 * @package Coywolf_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require __DIR__ . '/../includes/class-coywolf-seo-robots-matcher.php';
require __DIR__ . '/../includes/class-coywolf-seo-robots-rep.php';

$GLOBALS['n']    = 0;
$GLOBALS['fail'] = 0;

function ok( $label, $got, $want ) {
	$GLOBALS['n']++;
	if ( $got !== $want ) {
		$GLOBALS['fail']++;
		printf( "FAIL  %-52s got=%s want=%s\n", $label, var_export( $got, true ), var_export( $want, true ) );
	}
}

$REP = 'Coywolf_SEO_Robots_Rep';

/** allowed($robots,$agent,$url) — true means "allowed to fetch". */
function al( $robots, $agent, $url ) {
	return Coywolf_SEO_Robots_Rep::one_agent_allowed( $robots, $agent, $url );
}

echo "== GoogleOnly_SystemTest ==\n";
$r1 = "user-agent: FooBot\ndisallow: /\n";
ok( 'empty robots allows', al( '', 'FooBot', '' ), true );
ok( 'empty agent allowed', al( $r1, '', '' ), true );
ok( 'FooBot disallow / empty url', al( $r1, 'FooBot', '' ), false );
ok( 'all empty allowed', al( '', '', '' ), true );

echo "== ID_LineSyntax_Line (missing colon) ==\n";
$u = 'http://foo.bar/x/y';
ok( 'correct', al( "user-agent: FooBot\ndisallow: /\n", 'FooBot', $u ), false );
ok( 'unknown keys allow', al( "foo: FooBot\nbar: /\n", 'FooBot', $u ), true );
ok( 'missing colon accepted', al( "user-agent FooBot\ndisallow /\n", 'FooBot', $u ), false );

echo "== ID_LineSyntax_Groups ==\n";
$g = "allow: /foo/bar/\n\nuser-agent: FooBot\ndisallow: /\nallow: /x/\nuser-agent: BarBot\ndisallow: /\nallow: /y/\n\n\nallow: /w/\nuser-agent: BazBot\n\nuser-agent: FooBot\nallow: /z/\ndisallow: /\n";
ok( 'FooBot /x/b', al( $g, 'FooBot', 'http://foo.bar/x/b' ), true );
ok( 'FooBot /z/d', al( $g, 'FooBot', 'http://foo.bar/z/d' ), true );
ok( 'FooBot /y/c', al( $g, 'FooBot', 'http://foo.bar/y/c' ), false );
ok( 'BarBot /y/c', al( $g, 'BarBot', 'http://foo.bar/y/c' ), true );
ok( 'BarBot /w/a', al( $g, 'BarBot', 'http://foo.bar/w/a' ), true );
ok( 'BarBot /z/d', al( $g, 'BarBot', 'http://foo.bar/z/d' ), false );
ok( 'BazBot /z/d', al( $g, 'BazBot', 'http://foo.bar/z/d' ), true );
ok( 'FooBot /foo/bar/ (orphan ignored)', al( $g, 'FooBot', 'http://foo.bar/foo/bar/' ), false );
ok( 'BarBot /foo/bar/', al( $g, 'BarBot', 'http://foo.bar/foo/bar/' ), false );
ok( 'BazBot /foo/bar/', al( $g, 'BazBot', 'http://foo.bar/foo/bar/' ), false );

echo "== ID_LineSyntax_Groups_OtherRules ==\n";
$o1 = "User-agent: BarBot\nSitemap: https://foo.bar/sitemap\nUser-agent: *\nDisallow: /\n";
ok( 'sitemap no-close FooBot', al( $o1, 'FooBot', 'http://foo.bar/' ), false );
ok( 'sitemap no-close BarBot', al( $o1, 'BarBot', 'http://foo.bar/' ), false );
$o2 = "User-agent: FooBot\nInvalid-Unknown-Line: unknown\nUser-agent: *\nDisallow: /\n";
ok( 'unknown no-close FooBot', al( $o2, 'FooBot', 'http://foo.bar/' ), false );
ok( 'unknown no-close BarBot', al( $o2, 'BarBot', 'http://foo.bar/' ), false );

echo "== ID_REPLineNamesCaseInsensitive ==\n";
$upper = "USER-AGENT: FooBot\nALLOW: /x/\nDISALLOW: /\n";
$lower = "user-agent: FooBot\nallow: /x/\ndisallow: /\n";
$camel = "uSeR-aGeNt: FooBot\nAlLoW: /x/\ndIsAlLoW: /\n";
$alw   = 'http://foo.bar/x/y';
$dis   = 'http://foo.bar/a/b';
foreach ( array( 'upper' => $upper, 'lower' => $lower, 'camel' => $camel ) as $k => $rr ) {
	ok( "$k allowed", al( $rr, 'FooBot', $alw ), true );
	ok( "$k disallowed", al( $rr, 'FooBot', $dis ), false );
}

echo "== ID_VerifyValidUserAgentsToObey ==\n";
ok( 'Foobot', $REP::is_valid_user_agent_to_obey( 'Foobot' ), true );
ok( 'Foobot-Bar', $REP::is_valid_user_agent_to_obey( 'Foobot-Bar' ), true );
ok( 'Foo_Bar', $REP::is_valid_user_agent_to_obey( 'Foo_Bar' ), true );
ok( 'empty', $REP::is_valid_user_agent_to_obey( '' ), false );
ok( 'tsu', $REP::is_valid_user_agent_to_obey( "\xE3\x83\x84" ), false );
ok( 'Foobot*', $REP::is_valid_user_agent_to_obey( 'Foobot*' ), false );
ok( 'space Foobot space', $REP::is_valid_user_agent_to_obey( ' Foobot ' ), false );
ok( 'Foobot/2.1', $REP::is_valid_user_agent_to_obey( 'Foobot/2.1' ), false );
ok( 'Foobot Bar', $REP::is_valid_user_agent_to_obey( 'Foobot Bar' ), false );

echo "== ID_UserAgentValueCaseInsensitive ==\n";
$uu = "User-Agent: FOO BAR\nAllow: /x/\nDisallow: /\n";
$ul = "User-Agent: foo bar\nAllow: /x/\nDisallow: /\n";
$uc = "User-Agent: FoO bAr\nAllow: /x/\nDisallow: /\n";
foreach ( array( $uu, $ul, $uc ) as $rr ) {
	ok( 'Foo allowed', al( $rr, 'Foo', $alw ), true );
	ok( 'Foo disallowed', al( $rr, 'Foo', $dis ), false );
	ok( 'foo allowed', al( $rr, 'foo', $alw ), true );
	ok( 'foo disallowed', al( $rr, 'foo', $dis ), false );
}

echo "== GoogleOnly_AcceptUserAgentUpToFirstSpace ==\n";
ok( 'Foobot Bar invalid', $REP::is_valid_user_agent_to_obey( 'Foobot Bar' ), false );
$sp = "User-Agent: *\nDisallow: /\nUser-Agent: Foo Bar\nAllow: /x/\nDisallow: /\n";
ok( 'Foo allowed (token to space)', al( $sp, 'Foo', 'http://foo.bar/x/y' ), true );
ok( 'Foo Bar disallowed', al( $sp, 'Foo Bar', 'http://foo.bar/x/y' ), false );

echo "== ID_GlobalGroups_Secondary ==\n";
$global  = "user-agent: *\nallow: /\nuser-agent: FooBot\ndisallow: /\n";
$only    = "user-agent: FooBot\nallow: /\nuser-agent: BarBot\ndisallow: /\nuser-agent: BazBot\ndisallow: /\n";
$uxy     = 'http://foo.bar/x/y';
ok( 'empty FooBot', al( '', 'FooBot', $uxy ), true );
ok( 'global FooBot', al( $global, 'FooBot', $uxy ), false );
ok( 'global BarBot falls back to *', al( $global, 'BarBot', $uxy ), true );
ok( 'only_specific QuxBot no group', al( $only, 'QuxBot', $uxy ), true );

echo "== ID_AllowDisallow_Value_CaseSensitive ==\n";
ok( 'lowercase path matches', al( "user-agent: FooBot\ndisallow: /x/\n", 'FooBot', 'http://foo.bar/x/y' ), false );
ok( 'uppercase path no match', al( "user-agent: FooBot\ndisallow: /X/\n", 'FooBot', 'http://foo.bar/x/y' ), true );

echo "== ID_LongestMatch ==\n";
$ux = 'http://foo.bar/x/page.html';
ok( 'dis longer', al( "user-agent: FooBot\ndisallow: /x/page.html\nallow: /x/\n", 'FooBot', $ux ), false );
ok( 'allow longer', al( "user-agent: FooBot\nallow: /x/page.html\ndisallow: /x/\n", 'FooBot', $ux ), true );
ok( 'allow longer, /x/ dis', al( "user-agent: FooBot\nallow: /x/page.html\ndisallow: /x/\n", 'FooBot', 'http://foo.bar/x/' ), false );
ok( 'both empty -> allow', al( "user-agent: FooBot\ndisallow: \nallow: \n", 'FooBot', $ux ), true );
ok( 'both / -> allow (tie)', al( "user-agent: FooBot\ndisallow: /\nallow: /\n", 'FooBot', $ux ), true );
ok( 'dis /x allow /x/ on /x', al( "user-agent: FooBot\ndisallow: /x\nallow: /x/\n", 'FooBot', 'http://foo.bar/x' ), false );
ok( 'dis /x allow /x/ on /x/', al( "user-agent: FooBot\ndisallow: /x\nallow: /x/\n", 'FooBot', 'http://foo.bar/x/' ), true );
ok( 'equal page.html -> allow', al( "user-agent: FooBot\ndisallow: /x/page.html\nallow: /x/page.html\n", 'FooBot', $ux ), true );
ok( 'allow /page dis *.html on page.html', al( "user-agent: FooBot\nallow: /page\ndisallow: /*.html\n", 'FooBot', 'http://foo.bar/page.html' ), false );
ok( 'allow /page dis *.html on /page', al( "user-agent: FooBot\nallow: /page\ndisallow: /*.html\n", 'FooBot', 'http://foo.bar/page' ), true );
ok( 'allow /x/page. dis *.html on page.html', al( "user-agent: FooBot\nallow: /x/page.\ndisallow: /*.html\n", 'FooBot', $ux ), true );
ok( 'allow /x/page. dis *.html on /x/y.html', al( "user-agent: FooBot\nallow: /x/page.\ndisallow: /*.html\n", 'FooBot', 'http://foo.bar/x/y.html' ), false );
$grp = "User-agent: *\nDisallow: /x/\nUser-agent: FooBot\nDisallow: /y/\n";
ok( 'specific shadows global /x/', al( $grp, 'FooBot', 'http://foo.bar/x/page' ), true );
ok( 'specific /y/ blocks', al( $grp, 'FooBot', 'http://foo.bar/y/page' ), false );

echo "== ID_Encoding ==\n";
ok( 'query passthrough', al( "User-agent: FooBot\nDisallow: /\nAllow: /foo/bar?qux=taz&baz=http://foo.bar?tar&par\n", 'FooBot', 'http://foo.bar/foo/bar?qux=taz&baz=http://foo.bar?tar&par' ), true );
$utf = "User-agent: FooBot\nDisallow: /\nAllow: /foo/bar/\xE3\x83\x84\n";
ok( 'utf rule, encoded url', al( $utf, 'FooBot', 'http://foo.bar/foo/bar/%E3%83%84' ), true );
ok( 'utf rule, raw url', al( $utf, 'FooBot', "http://foo.bar/foo/bar/\xE3\x83\x84" ), false );
$enc = "User-agent: FooBot\nDisallow: /\nAllow: /foo/bar/%E3%83%84\n";
ok( 'encoded rule, encoded url', al( $enc, 'FooBot', 'http://foo.bar/foo/bar/%E3%83%84' ), true );
ok( 'encoded rule, raw url', al( $enc, 'FooBot', "http://foo.bar/foo/bar/\xE3\x83\x84" ), false );
$baz = "User-agent: FooBot\nDisallow: /\nAllow: /foo/bar/%62%61%7A\n";
ok( 'encoded-ascii rule, decoded url', al( $baz, 'FooBot', 'http://foo.bar/foo/bar/baz' ), false );
ok( 'encoded-ascii rule, encoded url', al( $baz, 'FooBot', 'http://foo.bar/foo/bar/%62%61%7A' ), true );

echo "== ID_SpecialCharacters ==\n";
$s1 = "User-agent: FooBot\nDisallow: /foo/bar/quz\nAllow: /foo/*/qux\n";
ok( 's1 /foo/bar/quz', al( $s1, 'FooBot', 'http://foo.bar/foo/bar/quz' ), false );
ok( 's1 /foo/quz', al( $s1, 'FooBot', 'http://foo.bar/foo/quz' ), true );
ok( 's1 /foo//quz', al( $s1, 'FooBot', 'http://foo.bar/foo//quz' ), true );
ok( 's1 /foo/bax/quz', al( $s1, 'FooBot', 'http://foo.bar/foo/bax/quz' ), true );
$s2 = "User-agent: FooBot\nDisallow: /foo/bar\$\nAllow: /foo/bar/qux\n";
ok( 's2 /foo/bar', al( $s2, 'FooBot', 'http://foo.bar/foo/bar' ), false );
ok( 's2 /foo/bar/qux', al( $s2, 'FooBot', 'http://foo.bar/foo/bar/qux' ), true );
ok( 's2 /foo/bar/', al( $s2, 'FooBot', 'http://foo.bar/foo/bar/' ), true );
ok( 's2 /foo/bar/baz', al( $s2, 'FooBot', 'http://foo.bar/foo/bar/baz' ), true );
$s3 = "User-agent: FooBot\n# Disallow: /\nDisallow: /foo/quz#qux\nAllow: /\n";
ok( 's3 /foo/bar', al( $s3, 'FooBot', 'http://foo.bar/foo/bar' ), true );
ok( 's3 /foo/quz', al( $s3, 'FooBot', 'http://foo.bar/foo/quz' ), false );

echo "== GoogleOnly_IndexHTMLisDirectory ==\n";
$idx = "User-Agent: *\nAllow: /allowed-slash/index.html\nDisallow: /\n";
ok( 'idx /allowed-slash/', al( $idx, 'foobot', 'http://foo.com/allowed-slash/' ), true );
ok( 'idx /allowed-slash/index.htm', al( $idx, 'foobot', 'http://foo.com/allowed-slash/index.htm' ), false );
ok( 'idx /allowed-slash/index.html', al( $idx, 'foobot', 'http://foo.com/allowed-slash/index.html' ), true );
ok( 'idx /anyother-url', al( $idx, 'foobot', 'http://foo.com/anyother-url' ), false );

echo "== GoogleOnly_LineTooLong ==\n";
$max_line = 2083 * 8;
$longline  = '/x/';
$max_len   = $max_line - strlen( '/x/' ) - strlen( 'disallow: ' ) + 1;
$longline .= str_repeat( 'a', max( 0, $max_len - strlen( $longline ) ) );
$rt = "user-agent: FooBot\ndisallow: " . $longline . "/qux\n";
ok( 'too-long dis: /fux allowed', al( $rt, 'FooBot', 'http://foo.bar/fux' ), true );
ok( 'too-long dis: matches cutoff', al( $rt, 'FooBot', 'http://foo.bar' . $longline . '/fux' ), false );
$la = '/x/';
$lb = '/x/';
$max_len2 = $max_line - strlen( '/x/' ) - strlen( 'allow: ' ) + 1;
while ( strlen( $la ) < $max_len2 ) {
	$la .= 'a';
	$lb .= 'b';
}
$rt2 = "user-agent: FooBot\ndisallow: /\nallow: " . $la . "/qux\nallow: " . $lb . "/qux\n";
ok( 'too-long allow: / disallowed', al( $rt2, 'FooBot', 'http://foo.bar/' ), false );
ok( 'too-long allow: exact match', al( $rt2, 'FooBot', 'http://foo.bar' . $la . '/qux' ), true );
ok( 'too-long allow: cutoff match', al( $rt2, 'FooBot', 'http://foo.bar' . $lb . '/fux' ), true );

echo "== GoogleOnly_DocumentationChecks ==\n";
$base = 'http://foo.bar';
$fish = "user-agent: FooBot\ndisallow: /\nallow: /fish\n";
foreach ( array(
	'/bar' => false, '/fish' => true, '/fish.html' => true, '/fish/salmon.html' => true,
	'/fishheads' => true, '/fishheads/yummy.html' => true, '/fish.html?id=anything' => true,
	'/Fish.asp' => false, '/catfish' => false, '/?id=fish' => false,
) as $p => $exp ) {
	ok( "/fish $p", al( $fish, 'FooBot', $base . $p ), $exp );
}
$fishstar = "user-agent: FooBot\ndisallow: /\nallow: /fish*\n";
foreach ( array(
	'/bar' => false, '/fish' => true, '/fish.html' => true, '/fish/salmon.html' => true,
	'/fishheads' => true, '/fishheads/yummy.html' => true, '/fish.html?id=anything' => true,
	'/Fish.bar' => false, '/catfish' => false, '/?id=fish' => false,
) as $p => $exp ) {
	ok( "/fish* $p", al( $fishstar, 'FooBot', $base . $p ), $exp );
}
$fishslash = "user-agent: FooBot\ndisallow: /\nallow: /fish/\n";
foreach ( array(
	'/fish/' => true, '/fish/salmon' => true, '/fish/?salmon' => true, '/fish/salmon.html' => true,
	'/fish/?id=anything' => true, '/fish' => false, '/fish.html' => false, '/Fish/Salmon.html' => false,
) as $p => $exp ) {
	ok( "/fish/ $p", al( $fishslash, 'FooBot', $base . $p ), $exp );
}
$php = "user-agent: FooBot\ndisallow: /\nallow: /*.php\n";
foreach ( array(
	'/bar' => false, '/filename.php' => true, '/folder/filename.php' => true,
	'/folder/filename.php?parameters' => true, '//folder/any.php.file.html' => true,
	'/filename.php/' => true, '/index?f=filename.php/' => true, '/php/' => false,
	'/index?php' => false, '/windows.PHP' => false,
) as $p => $exp ) {
	ok( "/*.php $p", al( $php, 'FooBot', $base . $p ), $exp );
}
$phpend = "user-agent: FooBot\ndisallow: /\nallow: /*.php\$\n";
foreach ( array(
	'/bar' => false, '/filename.php' => true, '/folder/filename.php' => true,
	'/filename.php?parameters' => false, '/filename.php/' => false, '/filename.php5' => false,
	'/php/' => false, '/filename?php' => false, '/aaaphpaaa' => false, '//windows.PHP' => false,
) as $p => $exp ) {
	ok( "/*.php\$ $p", al( $phpend, 'FooBot', $base . $p ), $exp );
}
$fishphp = "user-agent: FooBot\ndisallow: /\nallow: /fish*.php\n";
ok( '/fish*.php /fish.php', al( $fishphp, 'FooBot', $base . '/fish.php' ), true );
ok( '/fish*.php /fishheads/catfish.php?parameters', al( $fishphp, 'FooBot', $base . '/fishheads/catfish.php?parameters' ), true );
ok( '/fish*.php /Fish.PHP', al( $fishphp, 'FooBot', $base . '/Fish.PHP' ), false );
$ex = 'http://example.com';
ok( 'precedence allow /p dis /', al( "user-agent: FooBot\nallow: /p\ndisallow: /\n", 'FooBot', $ex . '/page' ), true );
ok( 'precedence tie /folder', al( "user-agent: FooBot\nallow: /folder\ndisallow: /folder\n", 'FooBot', $ex . '/folder/page' ), true );
ok( 'precedence dis *.htm longer', al( "user-agent: FooBot\nallow: /page\ndisallow: /*.htm\n", 'FooBot', $ex . '/page.htm' ), false );
ok( 'precedence allow /$ on /', al( "user-agent: FooBot\nallow: /\$\ndisallow: /\n", 'FooBot', $ex . '/' ), true );
ok( 'precedence allow /$ on /page.html', al( "user-agent: FooBot\nallow: /\$\ndisallow: /\n", 'FooBot', $ex . '/page.html' ), false );

echo "== TestGetPathParamsQuery ==\n";
$pp = array(
	array( '', '/' ), array( 'http://www.example.com', '/' ), array( 'http://www.example.com/', '/' ),
	array( 'http://www.example.com/a', '/a' ), array( 'http://www.example.com/a/', '/a/' ),
	array( 'http://www.example.com/a/b?c=http://d.e/', '/a/b?c=http://d.e/' ),
	array( 'http://www.example.com/a/b?c=d&e=f#fragment', '/a/b?c=d&e=f' ),
	array( 'example.com', '/' ), array( 'example.com/', '/' ), array( 'example.com/a', '/a' ),
	array( 'example.com/a/', '/a/' ), array( 'example.com/a/b?c=d&e=f#fragment', '/a/b?c=d&e=f' ),
	array( 'a', '/' ), array( 'a/', '/' ), array( '/a', '/a' ), array( 'a/b', '/b' ),
	array( 'example.com?a', '/?a' ), array( 'example.com/a;b#c', '/a;b' ), array( '//a/b/c', '/b/c' ),
);
foreach ( $pp as $c ) {
	ok( "path('{$c[0]}')", $REP::path_params_query( $c[0] ), $c[1] );
}

echo "== TestMaybeEscapePattern ==\n";
ok( 'escape url', Coywolf_SEO_Robots_Matcher::escape_pattern( 'http://www.example.com' ), 'http://www.example.com' );
ok( 'escape /a/b/c', Coywolf_SEO_Robots_Matcher::escape_pattern( '/a/b/c' ), '/a/b/c' );
ok( 'escape a-acute', Coywolf_SEO_Robots_Matcher::escape_pattern( "\xC3\xA1" ), '%C3%A1' );
ok( 'escape %aa', Coywolf_SEO_Robots_Matcher::escape_pattern( '%aa' ), '%AA' );

echo "== parser metadata (line counting / BOM / sitemap) ==\n";
function counts( $parsed ) {
	// Mirror Google's RobotsStatsReporter: last_line_seen is the line of the
	// last DIRECTIVE (known or unknown), not the total emitted line count.
	$valid = 0; $unknown = 0; $last = 0;
	foreach ( $parsed['directives'] as $d ) {
		if ( 'unknown' === $d['type'] ) { $unknown++; } else { $valid++; }
		$last = $d['line'];
	}
	return array( $valid, $unknown, $last );
}
$unix = "User-Agent: foo\nAllow: /some/path\nUser-Agent: bar\n\n\nDisallow: /\n";
$dos  = "User-Agent: foo\r\nAllow: /some/path\r\nUser-Agent: bar\r\n\r\n\r\nDisallow: /\r\n";
$mac  = "User-Agent: foo\rAllow: /some/path\rUser-Agent: bar\r\r\rDisallow: /\r";
$nonl = "User-Agent: foo\nAllow: /some/path\nUser-Agent: bar\n\n\nDisallow: /";
$mix  = "User-Agent: foo\nAllow: /some/path\r\nUser-Agent: bar\n\r\n\nDisallow: /";
foreach ( array( 'unix' => $unix, 'dos' => $dos, 'mac' => $mac, 'nonl' => $nonl, 'mix' => $mix ) as $k => $rr ) {
	list( $valid, $unknown, $lines ) = counts( $REP::parse( $rr ) );
	ok( "lines $k valid=4", $valid, 4 );
	ok( "lines $k last_line=6", $lines, 6 );
}
list( $v, $unk ) = counts( $REP::parse( "\xEF\xBB\xBFUser-Agent: foo\nAllow: /AnyValue\n" ) );
ok( 'bom full valid=2', $v, 2 );
ok( 'bom full unknown=0', $unk, 0 );
list( $v, $unk ) = counts( $REP::parse( "\xEF\xBBUser-Agent: foo\nAllow: /AnyValue\n" ) );
ok( 'bom partial2 valid=2', $v, 2 );
ok( 'bom partial2 unknown=0', $unk, 0 );
list( $v, $unk ) = counts( $REP::parse( "\xEFUser-Agent: foo\nAllow: /AnyValue\n" ) );
ok( 'bom partial1 valid=2', $v, 2 );
ok( 'bom partial1 unknown=0', $unk, 0 );
list( $v, $unk ) = counts( $REP::parse( "\xEF\x11\xBFUser-Agent: foo\nAllow: /AnyValue\n" ) );
ok( 'bom broken valid=1', $v, 1 );
ok( 'bom broken unknown=1', $unk, 1 );
list( $v, $unk ) = counts( $REP::parse( "User-Agent: foo\n\xEF\xBB\xBFAllow: /AnyValue\n" ) );
ok( 'bom mid valid=1', $v, 1 );
ok( 'bom mid unknown=1', $unk, 1 );

echo "== ID_NonStandardLineExample_Sitemap ==\n";
function first_sitemap( $parsed ) {
	foreach ( $parsed['directives'] as $d ) {
		if ( 'sitemap' === $d['type'] ) {
			return $d['value'];
		}
	}
	return '';
}
$sm_end   = "User-Agent: foo\nAllow: /some/path\nUser-Agent: bar\n\n\nSitemap: http://foo.bar/sitemap.xml\n";
$sm_start = "Sitemap: http://foo.bar/sitemap.xml\nUser-Agent: foo\nAllow: /some/path\nUser-Agent: bar\n\n\n";
ok( 'sitemap at end', first_sitemap( $REP::parse( $sm_end ) ), 'http://foo.bar/sitemap.xml' );
ok( 'sitemap at start', first_sitemap( $REP::parse( $sm_start ) ), 'http://foo.bar/sitemap.xml' );

echo "\n----------------------------------------\n";
printf( "%d checks, %d failures\n", $GLOBALS['n'], $GLOBALS['fail'] );
exit( $GLOBALS['fail'] ? 1 : 0 );
