#!/usr/bin/env php
<?php

$COMPOSER = getenv("COMPOSER")?:"composer.json";
$COMPOSER_LOCK = getenv("COMPOSER_LOCK")?:"composer.lock";
$STACK = getenv("STACK")?:"cedar-14";

// prefix keys with "heroku-sys/"
function mkdep($require) { return array_combine(array_map(function($v) { return "heroku-sys/$v"; }, array_keys($require)), $require); }
// check if require section demands a runtime
function hasreq($require) { return isset($require["php"]) || isset($require["hhvm"]); }

function mkmetas($package, array &$metapaks, &$have_runtime_req = false) {
	// filter platform reqs
	$platfilter = function($v) { return preg_match("#^(hhvm$|php(-64bit)?$|ext-)#", $v); };
	
	// extract only platform requires, replaces and provides
	$preq = array_filter(isset($package["require"]) ? $package["require"] : [], $platfilter, ARRAY_FILTER_USE_KEY);
	$prep = array_filter(isset($package["replace"]) ? $package["replace"] : [], $platfilter, ARRAY_FILTER_USE_KEY);
	$ppro = array_filter(isset($package["provide"]) ? $package["provide"] : [], $platfilter, ARRAY_FILTER_USE_KEY);
	$pcon = array_filter(isset($package["conflict"]) ? $package["conflict"] : [], $platfilter, ARRAY_FILTER_USE_KEY);
	if(!$preq && !$prep && !$ppro && !$pcon) return false;
	$have_runtime_req |= hasreq($preq);
	$metapaks[] = [
		"type" => "metapackage",
		// we re-use the dep name and version, makes for nice error messages if dependencies cannot be fulfilled :)
		"name" => $package["name"],
		"version" => $package["version"],
		"require" => mkdep($preq),
		"replace" => mkdep($prep),
		"provide" => mkdep($ppro),
		"conflict" => mkdep($pcon),
	];
	return true;
}

// remove first arg (0)
array_shift($argv);
// base repos we need - no packagist, and the installer plugin path (first arg)
$repositories = [
	["packagist" => false],
	["type" => "path", "url" => array_shift($argv), "options" => ["symlink" => false]],
];
// all other args are repo URLs; they get passed in ascending order of precedence, so we reverse
foreach(array_reverse($argv) as $repo) $repositories[] = ["type" => "composer", "url" => $repo];

$json = json_decode(file_get_contents($COMPOSER), true);
if(!is_array($json)) exit(1);

$have_runtime_req = false;
$have_dev_runtime_req = false;
$require = [];
$requireDev = [];
if(file_exists($COMPOSER_LOCK)) {
	$lock = json_decode(file_get_contents($COMPOSER_LOCK), true);
	// basic lock file validity check
	if(!$lock || !isset($lock["platform"], $lock["platform-dev"], $lock["packages"], $lock["packages-dev"])) exit(1);
	if(!isset($lock["content-hash"]) && !isset($lock["hash"])) exit(1);
	$have_runtime_req |= hasreq($lock["platform"]);
	$have_dev_runtime_req |= hasreq($lock["platform-dev"]);
	// for each package that has platform requirements we build a meta-package that we then depend on
	// we cannot simply join all those requirements together with " " or "," because of the precedence of the "|" operator: requirements "5.*," and "^5.3.9|^7.0", which should lead to a PHP 5 install, would combine into "5.*,^5.3.9|^7.0" (there is no way to group requirements), and that would give PHP 7
	$metapaks = [];
	// whatever is in the lock "platform" key will be turned into a meta-package too, named "composer.json/composer.lock"; same for "platform-dev"
	// this will result in an installer event for that meta-package, from which we can extract what extensions that are bundled (and hence "replace"d) with the runtime need to be enabled
	// if we do not do this, then a require for e.g. ext-curl or ext-mbstring in the main composer.json cannot be found by the installer plugin
	$root = [
		"name" => "$COMPOSER/$COMPOSER_LOCK",
		"version" => "dev-".($lock["content-hash"] ?? $lock['hash']),
		"require" => $lock["platform"],
	];
	$rootDev = [
		"name" => "$COMPOSER/$COMPOSER_LOCK-require-dev",
		"version" => "dev-".($lock["content-hash"] ?? $lock['hash']),
		"require" => $lock["platform-dev"],
	];
	// inject the root meta-packages into the read lock file so later code picks them up too
	if($root["require"]) {
		$lock["packages"][] = $root;
		$require = [
			$root["name"] => $root["version"],
		];
	}
	// same for platform-dev requirements, but they go into a require-dev section later, so only installs with --dev pull those in
	if($rootDev["require"]) {
		$lock["packages-dev"][] = $rootDev;
		$requireDev = [
			$rootDev["name"] => $rootDev["version"],
		];
	}
	
	// collect platform requirements from regular packages in lock file
	foreach($lock["packages"] as $package) {
		if(mkmetas($package, $metapaks, $have_runtime_req)) {
			$require[$package["name"]] = $package["version"];
		}
	}
	// collect platform requirements from dev packages in lock file
	foreach($lock["packages-dev"] as $package) {
		if(mkmetas($package, $metapaks, $have_dev_runtime_req)) {
			$requireDev[$package["name"]] = $package["version"];
		}
	}
	
	// add all meta-packages to one local package repo
	if($metapaks) $repositories[] = ["type" => "package", "package" => $metapaks];
}

// if no PHP or HHVM is required anywhere, we need to add something
if(!$have_runtime_req) {
	if($have_dev_runtime_req) {
		// there is no requirement for a PHP or HHVM version in "require", nor in any dependencies therein, but there is one in "require-dev"
		// that's problematic, because requirements in there may effectively result in a rule like "7.0.*", but we'd next write "^5.5.17" into our "require" to have a sane default, and that'd blow up in CI where dev dependenies are installed
		// we can't compute a resulting version rule (that's the whole point of the custom installer that uses Composer's solver), so throwing an error is the best thing we can do here
		file_put_contents("php://stderr", "ERROR: neither your $COMPOSER 'require' section nor any\ndependency therein requires a runtime version, but 'require-dev'\nor a dependency therein does. Heroku cannot automatically select\na default runtime version in this case.\nPlease add a version requirement for 'php' to section 'require'\nin $COMPOSER, 'composer update', commit, and deploy again.");
		exit(3);
	}
	file_put_contents("php://stderr", "NOTICE: No runtime required in $COMPOSER_LOCK; using PHP ". ($require["heroku-sys/php"] = "^5.5.17") . "\n");
} elseif(!isset($root["require"]["php"]) && !isset($root["require"]["hhvm"])) {
	file_put_contents("php://stderr", "NOTICE: No runtime required in $COMPOSER; requirements\nfrom dependencies in $COMPOSER_LOCK will be used for selection\n");
}

$require["heroku-sys/apache"] = "^2.4.10";
$require["heroku-sys/nginx"] = "~1.8.0";

preg_match("#^([^-]+)(?:-([0-9]+))?\$#", $STACK, $stack);
$provide = ["heroku-sys/".$stack[1] => (isset($stack[2])?$stack[2]:"1").gmdate(".Y.m.d")]; # cedar: 14.2016.02.16 etc
$json = [
	"config" => ["cache-files-ttl" => 0, "discard-changes" => true],
	"minimum-stability" => isset($lock["minimum-stability"]) ? $lock["minimum-stability"] : "stable",
	"prefer-stable" => isset($lock["prefer-stable"]) ? $lock["prefer-stable"] : false,
	"provide" => $provide,
	"require" => $require,
	"require-dev" => (object)$requireDev,
	// put require before repositories, or a large number of metapackages from above will cause Composer's regexes to hit PCRE limits for backtracking or JIT stack size
	"repositories" => $repositories,
];
echo json_encode($json, JSON_PRETTY_PRINT);
