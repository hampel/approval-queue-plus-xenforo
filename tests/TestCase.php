<?php

namespace Tests;

use Hampel\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
	/**
	 * @var string $rootDir path to the XenForo root, relative to the add-on directory
	 *
	 * Four levels, because the add-on id carries a vendor: src/addons/Hampel/ApprovalQueuePlus
	 */
	protected $rootDir = '../../../..';

	/**
	 * @var array $addonsToLoad load this add-on alone
	 *
	 * Isolation keeps every other add-on's listeners, class extensions and vendored PHPUnit out
	 * of the test application. It needs framework 5.0 or later to be complete: below that,
	 * `app_setup` listeners were not filtered at all, and this add-on's extensions on pre-2.3
	 * class names (`XF\Service\User\Registration`, `XF\Admin\Controller\Tools`) were silently
	 * not applied - so a test of the registration write path ran XenForo's class, not ours.
	 */
	protected $addonsToLoad = ['Hampel/ApprovalQueuePlus'];
}
