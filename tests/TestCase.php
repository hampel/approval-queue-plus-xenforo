<?php

namespace Tests;

use Hampel\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
	use CreatesApplication;

	/**
	 * @var string $rootDir path to the XenForo root, relative to the add-on directory
	 *
	 * Four levels, because the add-on id carries a vendor: src/addons/Hampel/ApprovalQueuePlus
	 */
	protected $rootDir = '../../../..';

	/**
	 * @var array $addonsToLoad load this add-on alone
	 *
	 * Isolation is not cosmetic here: it keeps another add-on's vendored PHPUnit off the class
	 * loader, which is what makes a suite die before its first test with an unrelated error.
	 */
	protected $addonsToLoad = ['Hampel/ApprovalQueuePlus'];
}
