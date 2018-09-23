<?php
/**
 * Twigpack plugin for Craft CMS 3.x
 *
 * Twigpack is the conduit between Twig and webpack, with manifest.json &
 * webpack-dev-server HMR support
 *
 * @link      https://nystudio107.com/
 * @copyright Copyright (c) 2018 nystudio107
 */

namespace nystudio107\twigpack\helpers;

use Craft;
use craft\helpers\Json as JsonHelper;
use craft\helpers\UrlHelper;

use yii\base\Exception;
use yii\caching\TagDependency;
use yii\web\NotFoundHttpException;

/**
 * @author    nystudio107
 * @package   Twigpack
 * @since     1.0.0
 */
class Manifest
{
    // Constants
    // =========================================================================

    const CACHE_KEY = 'twigpack';
    const CACHE_TAG = 'twigpack';

    const DEVMODE_CACHE_DURATION = 1;

    // Protected Static Properties
    // =========================================================================

    /**
     * @var array
     */
    protected static $files;

    // Public Static Methods
    // =========================================================================

    /**
     * @param array  $config
     * @param string $moduleName
     * @param bool   $async
     *
     * @return null|string
     * @throws NotFoundHttpException
     */
    public static function getCssModuleTags(array $config, string $moduleName, bool $async)
    {
        $legacyModule = self::getModule($config, $moduleName, 'legacy');
        if ($legacyModule === null) {
            return null;
        }
        $lines = [];
        if ($async) {
            $lines[] = "<link rel=\"preload\" href=\"{$legacyModule}\" as=\"style\" onload=\"this.rel='stylesheet'\" />";
            $lines[] = "<noscript><link rel=\"stylesheet\" href=\"{$legacyModule}\"></noscript>";
        } else {
            $lines[] = "<link rel=\"stylesheet\" href=\"{$legacyModule}\" />";
        }

        return implode("\r\n", $lines);
    }

    /**
     * @param array  $config
     * @param string $moduleName
     * @param bool   $async
     *
     * @return null|string
     * @throws NotFoundHttpException
     */
    public static function getJsModuleTags(array $config, string $moduleName, bool $async)
    {
        $legacyModule = self::getModule($config, $moduleName, 'legacy');
        if ($legacyModule === null) {
            return null;
        }
        if ($async) {
            $modernModule = self::getModule($config, $moduleName, 'modern');
            if ($modernModule === null) {
                return null;
            }
        }
        $lines = [];
        if ($async) {
            $lines[] = "<script type=\"module\" src=\"{$modernModule}\"></script>";
            $lines[] = "<script nomodule src=\"{$legacyModule}\"></script>";
        } else {
            $lines[] = "<script src=\"{$legacyModule}\"></script>";
        }

        return implode("\r\n", $lines);
    }

    /**
     * Safari 10.1 supports modules, but does not support the `nomodule`
     * attribute - it will load <script nomodule> anyway. This snippet solve
     * this problem, but only for script tags that load external code, e.g.:
     * <script nomodule src="nomodule.js"></script>
     *
     * Again: this will **not* # prevent inline script, e.g.:
     * <script nomodule>alert('no modules');</script>.
     *
     * This workaround is possible because Safari supports the non-standard
     * 'beforeload' event. This allows us to trap the module and nomodule load.
     *
     * Note also that `nomodule` is supported in later versions of Safari -
     * it's just 10.1 that omits this attribute.
     *
     * c.f.: https://gist.github.com/samthor/64b114e4a4f539915a95b91ffd340acc
     *
     * @return string
     */
    public static function getSafariNomoduleFix(): string
    {
        return <<<EOT
<script>
!function(){var e=document,t=e.createElement("script");if(!("noModule"in t)&&"onbeforeload"in t){var n=!1;e.addEventListener("beforeload",function(e){if(e.target===t)n=!0;else if(!e.target.hasAttribute("nomodule")||!n)return;e.preventDefault()},!0),t.type="module",t.src=".",e.head.appendChild(t),t.remove()}}();
</script>
EOT;
    }

    /**
     * @param array  $config
     * @param string $moduleName
     * @param string $type
     *
     * @return null|string
     * @throws NotFoundHttpException
     */
    public static function getModule(array $config, string $moduleName, string $type = 'modern')
    {
        $devMode = Craft::$app->getConfig()->getGeneral()->devMode;
        $isHot = ($devMode && $config['useDevServer']);
        $manifest = null;
        // Try to get the manifest
        while ($manifest === null) {
            $manifestPath = $isHot
                ? $config['devServer']['manifestPath']
                : $config['server']['manifestPath'];
            $manifest = self::getManifestFile($config['manifest'][$type], $manifestPath);
            // If the manigest isn't found, and it was hot, fall back on non-hot
            if ($manifest === null) {
                Craft::error(
                    Craft::t(
                        'twigpack',
                        'Manifest file not found at: {manifestPath}',
                        ['manifestPath' => $manifestPath]
                    ),
                    __METHOD__
                );
                if ($isHot) {
                    // Try again, but not with home module replacement
                    $isHot = false;
                } else {
                    if ($devMode) {
                        // We couldn't find a manifest; throw an error
                        throw new NotFoundHttpException(
                            Craft::t(
                                'twigpack',
                                'Manifest file not found at: {manifestPath}',
                                ['manifestPath' => $manifestPath]
                            )
                        );
                    }

                    return null;
                }
            }
        }
        $module = $manifest[$moduleName];
        $prefix = $isHot
            ? $config['devServer']['publicPath']
            : $config['server']['publicPath'];
        // If the module isn't a full URL, prefix it
        if (!UrlHelper::isAbsoluteUrl($module)) {
            $module = self::combinePaths($prefix, $module);
        }
        // Make sure it's a full URL
        if (!UrlHelper::isAbsoluteUrl($module)) {
            try {
                $module = UrlHelper::siteUrl($module);
            } catch (Exception $e) {
                Craft::error($e->getMessage(), __METHOD__);
            }
        }

        return $module;
    }

    /**
     * Invalidate all of the manifest caches
     */
    public static function invalidateCaches()
    {
        $cache = Craft::$app->getCache();
        TagDependency::invalidate($cache, self::CACHE_TAG);
        Craft::info('All manifest caches cleared', __METHOD__);
    }

    // Protected Static Methods
    // =========================================================================

    /**
     * @param string $name
     * @param string $path
     *
     * @return mixed
     */
    protected static function getManifestFile(string $name, string $path)
    {
        // Normalize the path
        $path = self::combinePaths($path, $name);

        return self::getFileContents($path);
    }

    /**
     * @param string $path
     *
     * @return mixed
     */
    protected static function getFileContents(string $path)
    {
        // Make sure it's a full URL
        if (!UrlHelper::isAbsoluteUrl($path)) {
            try {
                $path = UrlHelper::siteUrl($path);
            } catch (Exception $e) {
                Craft::error($e->getMessage(), __METHOD__);
            }
        }
        // Return the memoized manifest if it exists
        if (!empty(self::$files[$path])) {
            return self::$files[$path];
        }
        // Create the dependency tags
        $dependency = new TagDependency([
            'tags' => [
                self::CACHE_TAG,
                self::CACHE_TAG.$path,
            ],
        ]);
        // Set the cache duraction based on devMode
        $cacheDuration = Craft::$app->getConfig()->getGeneral()->devMode
            ? self::DEVMODE_CACHE_DURATION
            : null;
        // Get the result from the cache, or parse the file
        $cache = Craft::$app->getCache();
        $file = $cache->getOrSet(
            self::CACHE_KEY.$path,
            function () use ($path) {
                $result = null;
                $string = @file_get_contents($path);
                if ($string) {
                    $result = JsonHelper::decodeIfJson($string);
                }

                return $result;
            },
            $cacheDuration,
            $dependency
        );
        self::$files[$path] = $file;

        return $file;
    }

    /**
     * Combined the passed in paths, whether file system or URL
     *
     * @param string ...$paths
     *
     * @return string
     */
    protected static function combinePaths(string ...$paths): string
    {
        $last_key = \count($paths) - 1;
        array_walk($paths, function (&$val, $key) use ($last_key) {
            switch ($key) {
                case 0:
                    $val = rtrim($val, '/ ');
                    break;
                case $last_key:
                    $val = ltrim($val, '/ ');
                    break;
                default:
                    $val = trim($val, '/ ');
                    break;
            }
        });

        $first = array_shift($paths);
        $last = array_pop($paths);
        $paths = array_filter($paths);
        array_unshift($paths, $first);
        $paths[] = $last;

        return implode('/', $paths);
    }
}
