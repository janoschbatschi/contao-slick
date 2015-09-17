<?php
/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2015 Heimrich & Hannot GmbH
 * @package slick
 * @author Rico Kaltofen <r.kaltofen@heimrich-hannot.de>
 * @license http://www.gnu.org/licences/lgpl-3.0.html LGPL
 */

namespace HeimrichHannot\Slick;

class SlickConfig extends \Controller
{
	public static function createConfigJs($objConfig, $debug = false)
	{
		if(!static::isJQueryEnabled()) return false;

		$cache = !$GLOBALS['TL_CONFIG']['debugMode'];

		$objT = new \FrontendTemplate('jquery.slick');

		$objT->config = static::createConfigJSON($objConfig);
		$objT->selector = static::getSlickContainerSelectorFromModel($objConfig);
		$objT->wrapperClass = static::getSlickCssClassFromModel($objConfig);

		if($objConfig->initCallback)
		{
			$objT->initCallback = $objConfig->initCallback;
		}

		$strFile = 'assets/js/' . $objT->wrapperClass . '.js';
		$strFileMinified = 'assets/js/' . $objT->wrapperClass . '.min.js';

		$objFile = new \File($strFile, file_exists(TL_ROOT . '/' . $strFile));
		$objFileMinified = new \File($strFileMinified, file_exists(TL_ROOT . '/' . $strFileMinified));

		$rewrite = $objConfig->tstamp > $objFile->mtime || $objFile->size == 0 || $objFileMinified == 0|| $debug;

		// simple file caching
		if($rewrite)
		{
			$strChunk = $objT->parse();
			$objFile->write($objT->parse());
			$objFile->close();

			// minify js
			if($cache)
			{
				$objFileMinified = new \File($strFileMinified);
				$objMinify = new \MatthiasMullie\Minify\JS();
				$objMinify->add($strChunk);
				$objFileMinified->write($objMinify->minify());
				$objFileMinified->close();
			}
		}

		$GLOBALS['TL_JAVASCRIPT']['slick'] = 'system/modules/slick/assets/vendor/slick.js/slick/slick' . ($cache ? '.min.js|static' : '.js');
		$GLOBALS['TL_JAVASCRIPT'][$objT->wrapperClass] = $cache ? ($strFileMinified . '|static') : $strFile;

	}

	public static function isJQueryEnabled()
	{
		global $objPage;

		$blnMobile = ($objPage->mobileLayout && \Environment::get('agent')->mobile);

		$intId = ($blnMobile && $objPage->mobileLayout) ? $objPage->mobileLayout : $objPage->layout;
		$objLayout = \LayoutModel::findByPk($intId);

		return $objLayout->addJQuery;
	}

	public static function getCssClassForContent($id)
	{
		return 'slick-content-'.  $id;
	}

	public static function getSlickContainerSelectorFromModel($objConfig)
	{
		return '.' . static::getSlickCssClassFromModel($objConfig) . ' .slick-container';
	}

	public static function getSlickCssClassFromModel($objConfig)
	{
		$strClass = static::stripNamespaceFromClassName($objConfig);

		return 'slick_' . substr(md5($strClass .'_'. $objConfig->id), 0, 6);
	}

	public static function getCssClassFromModel($objConfig)
	{
		return static::getSlickCssClassFromModel($objConfig) . (strlen($objConfig->cssClass) > 0 ? ' ' . $objConfig->cssClass : '');
	}


	public static function createConfigJSON($objConfig)
	{
		$arrConfig = static::createConfig($objConfig);

		$strJson = '';

		if(!is_array($arrConfig['config'])) return $strJson;

		$strJson = json_encode($arrConfig['config']);

		if(is_array($arrConfig['objects']))
		{
			foreach ($arrConfig['objects'] as $key)
			{
				// remove quotes from callbacks
				$strJson = preg_replace('#"' . $key . '":"(.+?)"#', '"' . $key . '":$1', $strJson);
			}
		}

		$strJson = ltrim($strJson, '{');
		$strJson = rtrim($strJson, '}');
		
		return $strJson;
	}

	public static function createConfig($objConfig)
	{
		\Controller::loadDataContainer('tl_slick_spread');

		$arrConfig = array();
		$arrObjects = array();

		foreach($objConfig->row() as $key => $value)
		{
			if(strstr($key, 'slick_') === false) continue;

			if(!isset($GLOBALS['TL_DCA']['tl_slick_spread']['fields'][$key])) continue;

			$arrData = $GLOBALS['TL_DCA']['tl_slick_spread']['fields'][$key];

			$slickKey = substr($key, 6); // trim slick_ prefix

			if($arrData['eval']['rgxp'] == 'digit')
			{
				$value = intval($value);
			}

			if($arrData['inputType'] == 'checkbox' && !$arrData['eval']['multiple'])
			{
				$value = (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);
			}

			if($arrData['eval']['multiple'] || $arrData['inputType'] == 'multiColumnWizard')
			{
				$value = deserialize($value, true);
			}

			if($arrData['eval']['isJsObject'])
			{
				$arrObjects[] = $slickKey;
			}

			// check type as well, otherwise
			if($value === '') continue;

			if($key == 'slick_responsive')
			{
				$arrResponsive = array();

				foreach($value as $config)
				{
					if(empty($config['slick_settings'])) continue;

					$objResponsiveConfig = SlickConfigModel::findByPk($config['slick_settings']);

					if($objResponsiveConfig === null) continue;

					$config['breakpoint'] = $config['slick_breakpoint'];
					unset($config['slick_breakpoint']);

					$settings = static::createConfig($objResponsiveConfig);

					if($settings['config']['unslick'])
					{
						$config['settings'] = 'unslick';
					}
					else
					{
						$config['settings'] = $settings['config'];
					}

					unset($config['slick_settings']);


					$arrResponsive[] = $config;
				}

				if(empty($arrResponsive))
				{
					$value = false;
				}
				else
				{
					$value = $arrResponsive;
				}
			}

			if($key == 'slick_asNavFor')
			{
				$objTargetConfig = SlickConfigModel::findByPk($value);

				if($objTargetConfig !== null)
				{
					$value = static::getSlickContainerSelectorFromModel($objTargetConfig);
				}
			}

			if($key)

			$arrConfig[$slickKey] = $value;
		}

		// remove responsive settings, otherwise center wont work
		if(empty($arrResponsive))
		{
			unset($arrConfig['responsive']);
		}

		$arrReturn = array
		(
			'config' 	=> $arrConfig,
			'objects'	=> $arrObjects
		);

		return $arrReturn;
	}

	public static function stripNamespaceFromClassName($obj)
	{
		$classname = get_class($obj);

		if (preg_match('@\\\\([\w]+)$@', $classname, $matches)) {
			$classname = $matches[1];
		}

		return $classname;
	}
}

