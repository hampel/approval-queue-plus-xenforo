
TODO: needs updating for v3.5.0

* Setup creates table `xf_aqp_user_data`

* Class extensions
	* `XF\Entity\User`
		* add `UserAgent` to relations structure
		* add `canViewUserAgents` function
	* `XF\Service\User\Registration`
		* intercept save process and save UserAgent as well

* template modifications
	* `approval_item_user`
		* Remove top info from approval queue
		* Add extra information to moderated user queue
	* `approval_queue.less`
		* add `.approvalQueuePlus` CSS
	* `PAGE_CONTAINER`
		* change default sort order on approval queue by modifiying link to add query param

* Code event listeners
	* `user_delete_clean_init`
		* delete entries from `xf_user_agent` when user is deleted

* Cron tasks
	* `Cron\CleanUp::runDailyCleanup`
		* removes old UserAgent records for users who are now valid

* permissions
	* `viewUserAgents`

* Fragile points:
	* template modifications
		* `approval_item_user` x2
		* `PAGE_CONTAINER`
		
* Testing:
	1. Enable manual approval in User registration options
	2. Register a new user account
	3. Check approval queue, you should see new options
		* tabular list of information: Username; Email; Joined; Last activity; Registration IP; Time Zone; User Agent; etc
	4. Change Default Queue Order in Options to Descending then refresh the home page - check that the link to the 
	approval queue had the `&direction=desc` query string added