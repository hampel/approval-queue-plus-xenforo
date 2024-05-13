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