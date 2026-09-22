<?php namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * All four modifications fail silently when a XenForo upgrade changes the template they target:
 * the page renders and the add-on's part of it is just missing.
 *
 * This reads the apply count XenForo recorded when it last compiled each template on the forum
 * the suite points at, so run it after upgrading that forum, not only after changing the add-on.
 */
class TemplateModificationsTest extends TestCase
{
	public static function modifications(): array
	{
		return [
			'queue item: user info macro'    => ['approvalQueuePlusApprovalItemUser'],
			'queue item: header removed'     => ['approvalQueuePlusRemoveTopInfo'],
			'queue stylesheet'               => ['approvalQueuePlusCss'],
			'queue link carries sort order'  => ['approvalQueuePlusPageContainerReverse'],
		];
	}

	#[DataProvider('modifications')]
	public function test_the_modification_applies($modificationKey)
	{
		$this->assertTemplateModificationApplied($modificationKey);
	}
}
