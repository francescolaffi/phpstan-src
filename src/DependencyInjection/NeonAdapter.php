<?php declare(strict_types = 1);

namespace PHPStan\DependencyInjection;

use Nette\DI\Config\Adapter;
use Nette\DI\Config\Helpers;
use Nette\DI\Definitions\Reference;
use Nette\DI\Definitions\Statement;
use Nette\Neon\Entity;
use Nette\Neon\Neon;
use PHPStan\File\FileHelper;

class NeonAdapter implements Adapter
{

	public const CACHE_KEY = 'v2';

	private const PREVENT_MERGING_SUFFIX = '!';

	/** @var FileHelper[] */
	private $fileHelpers;

	/**
	 * @param string $file
	 * @return mixed[]
	 */
	public function load(string $file): array
	{
		$contents = file_get_contents($file);
		if ($contents === false) {
			throw new \PHPStan\ShouldNotHappenException();
		}
		return $this->process((array) Neon::decode($contents), '', $file);
	}

	/**
	 * @param mixed[] $arr
	 * @return mixed[]
	 */
	public function process(array $arr, string $fileKey, string $file): array
	{
		$res = [];
		foreach ($arr as $key => $val) {
			if (is_string($key) && substr($key, -1) === self::PREVENT_MERGING_SUFFIX) {
				if (!is_array($val) && $val !== null) {
					throw new \Nette\DI\InvalidConfigurationException(sprintf('Replacing operator is available only for arrays, item \'%s\' is not array.', $key));
				}
				$key = substr($key, 0, -1);
				$val[Helpers::PREVENT_MERGING] = true;
			}

			if (is_array($val)) {
				if (!is_int($key)) {
					$fileKeyToPass = $fileKey . '[' . $key . ']';
				} else {
					$fileKeyToPass = $fileKey . '[]';
				}
				$val = $this->process($val, $fileKeyToPass, $file);

			} elseif ($val instanceof Entity) {
				if (!is_int($key)) {
					$fileKeyToPass = $fileKey . '(' . $key . ')';
				} else {
					$fileKeyToPass = $fileKey . '()';
				}
				if ($val->value === Neon::CHAIN) {
					$tmp = null;
					foreach ($this->process($val->attributes, $fileKeyToPass, $file) as $st) {
						$tmp = new Statement(
							$tmp === null ? $st->getEntity() : [$tmp, ltrim(implode('::', (array) $st->getEntity()), ':')],
							$st->arguments
						);
					}
					$val = $tmp;
				} else {
					$tmp = $this->process([$val->value], $fileKeyToPass, $file);
					$val = new Statement($tmp[0], $this->process($val->attributes, $fileKeyToPass, $file));
				}
			}

			$keyToResolve = $fileKey;
			if (is_int($key)) {
				$keyToResolve .= '[]';
			} else {
				$keyToResolve .= '[' . $key . ']';
			}

			if (in_array($keyToResolve, [
				'[parameters][autoload_files][]',
				'[parameters][autoload_directories][]',
				'[parameters][paths][]',
				'[parameters][excludes_analyse][]',
				'[parameters][ignoreErrors][][paths][]',
				'[parameters][ignoreErrors][][path]',
				'[parameters][bootstrap]',
				'[parameters][memoryLimitFile]',
				'[parameters][benchmarkFile]',
				'[parameters][symfony][console_application_loader]',
				'[parameters][doctrine][objectManagerLoader]',
			], true) && is_string($val) && strpos($val, '%') === false) {
				$fileHelper = $this->createFileHelperByFile($file);
				$val = $fileHelper->normalizePath($fileHelper->absolutizePath($val));
			}

			$res[$key] = $val;
		}
		return $res;
	}

	/**
	 * @param mixed[] $data
	 * @return string
	 */
	public function dump(array $data): string
	{
		array_walk_recursive(
			$data,
			static function (&$val): void {
				if (!($val instanceof Statement)) {
					return;
				}

				$val = self::statementToEntity($val);
			}
		);
		return "# generated by Nette\n\n" . Neon::encode($data, Neon::BLOCK);
	}

	private static function statementToEntity(Statement $val): Entity
	{
		array_walk_recursive(
			$val->arguments,
			static function (&$val): void {
				if ($val instanceof Statement) {
					$val = self::statementToEntity($val);
				} elseif ($val instanceof Reference) {
					$val = '@' . $val->getValue();
				}
			}
		);

		$entity = $val->getEntity();
		if ($entity instanceof Reference) {
			$entity = '@' . $entity->getValue();
		} elseif (is_array($entity)) {
			if ($entity[0] instanceof Statement) {
				return new Entity(
					Neon::CHAIN,
					[
						self::statementToEntity($entity[0]),
						new Entity('::' . $entity[1], $val->arguments),
					]
				);
			} elseif ($entity[0] instanceof Reference) {
				$entity = '@' . $entity[0]->getValue() . '::' . $entity[1];
			} elseif (is_string($entity[0])) {
				$entity = $entity[0] . '::' . $entity[1];
			}
		}
		return new Entity($entity, $val->arguments);
	}

	private function createFileHelperByFile(string $file): FileHelper
	{
		$dir = dirname($file);
		if (!isset($this->fileHelpers[$dir])) {
			$this->fileHelpers[$dir] = new FileHelper($dir);
		}

		return $this->fileHelpers[$dir];
	}

}
