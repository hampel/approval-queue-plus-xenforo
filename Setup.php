<?php namespace Hampel\ApprovalQueuePlus;

use XF\Db\Schema\Alter;
use XF\Db\Schema\Create;
use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerUpgradeTrait;

class Setup extends AbstractSetup
{
	use StepRunnerUpgradeTrait;

	public function install(array $stepParams = [])
	{
		$this->createTables();
	}

    // upgrade to 3.5.1
    public function upgrade3050170Step1(array $stepParams = [])
    {
        if ($this->schemaManager()->tableExists('xf_user_agent'))
        {
            $this->schemaManager()->renameTable('xf_user_agent', 'xf_aqp_user_data');
            $this->alterTables350();
        }
        elseif ($this->schemaManager()->tableExists('xf_aqp_user_agent'))
        {
            $this->schemaManager()->renameTable('xf_aqp_user_agent', 'xf_aqp_user_data');
            $this->alterTables350();
        }
        elseif ($this->schemaManager()->tableExists('xf_aqp_user_data'))
        {
            // we renamed the table, but didn't create the new columns - oops
            if (!$this->schemaManager()->columnExists('xf_aqp_user_data', 'iso_code'))
            {
                $this->alterTables350();
            }
        }
        else
        {
            $this->createTables();
        }
    }

    // upgrade to 3.6.0
    public function upgrade3060011Step1(array $stepParams = [])
    {
        $permission = \XF::finder('XF:Permission')
            ->where('permission_group_id', 'general')
            ->where('permission_id', 'viewUserAgents')
            ->fetchOne();

        if (!$permission || $permission->addon_id != 'Hampel/ApprovalQueuePlus')
        {
            // already renamed, or the unprefixed id belongs to somebody else
            return;
        }

        // Rename it rather than replacing it. Permission::_postSave() moves every grant in
        // xf_permission_entry and xf_permission_entry_content over to the new id, renames the
        // master phrase and queues the permission rebuild. Deleting the old permission and
        // creating a new one instead runs _postDelete(), which deletes those grants outright -
        // so every forum would silently lose the permission and have to grant it again.
        //
        // Setup steps run before the add-on data import on both the CLI and admin panel paths,
        // so the import then matches this renamed row rather than creating a second one, and
        // nothing is left for deleteOrphanedAddOnData() to remove.
        //
        // The behavior is addressed by its short name because XF 2.2 looks it up literally;
        // only 2.3 resolves a class name to it, and this add-on supports both.
        $permission->getBehavior('XF:DevOutputWritable')->setOption('write_dev_output', false);
        $permission->permission_id = 'hampelAqpViewUserAgents';
        $permission->save();
    }

    public function postUpgrade($previousVersion, array &$stateChanges)
    {
        if (\XF::$versionId >= 2030000) { // XF 2.3+
            $this->enqueuePostUpgradeCleanUp();
        }
    }

	public function uninstall(array $stepParams = [])
	{
		$this->schemaManager()->dropTable('xf_aqp_user_data');
	}

	protected function createTables()
	{
		$this->schemaManager()->createTable('xf_aqp_user_data', function(Create $table)
		{
			$table->addColumn('user_id', 'int');
			$table->addColumn('user_agent', 'text');
            $table->addColumn('iso_code', 'varchar', 2);
            $table->addColumn('cf_location', 'mediumblob');
			$table->addPrimaryKey('user_id');
		});
	}

    // for v3.5.0+
    protected function alterTables350()
    {
        $this->schemaManager()->alterTable('xf_aqp_user_data', function(Alter $table)
        {
            $table->addColumn('iso_code', 'varchar', 2)->setDefault('')->after('user_agent');
            $table->addColumn('cf_location', 'mediumblob')->after('iso_code');
        });
    }
}