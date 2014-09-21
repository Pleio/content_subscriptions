<?php
/**
 * All helper functions form this plugin are bundled here
 */

/**
 * Check if the user has a subscription with the content
 *
 * @param int $entity_guid the content entity to check
 * @param int $user_guid   the user to check (defaults to current user)
 *
 * @return bool
 */
function content_subscriptions_check_subscription($entity_guid, $user_guid = 0) {
	$result = false;
	
	$entity_guid = sanitise_int($entity_guid, false);
	$user_guid = sanitise_int($user_guid, false);
	
	if (empty($user_guid)) {
		$user_guid = elgg_get_logged_in_user_guid();
	}
	
	if (!empty($user_guid) && !empty($entity_guid)) {
		$result = check_entity_relationship($user_guid, CONTENT_SUBCRIPTIONS_SUBSCRIPTION, $entity_guid);
	}
	
	return $result;
}

/**
 * Subscribe a user to the updates of an entity
 *
 * @param int $entity_guid the content entity to subscribe to
 * @param int $user_guid   the user to subscribe (defaults to current user)
 *
 * @return bool
 */
function content_subscriptions_subscribe($entity_guid, $user_guid = 0) {
	$entity_guid = sanitise_int($entity_guid, false);
	$user_guid = sanitise_int($user_guid, false);
	
	if (empty($user_guid)) {
		$user_guid = elgg_get_logged_in_user_guid();
	}
	
	if (!content_subscriptions_check_subscription($entity_guid, $user_guid)) {
		// remove the block flag
		remove_entity_relationship($user_guid, CONTENT_SUBCRIPTIONS_BLOCK, $entity_guid);
		
		// add subscription
		$result = add_entity_relationship($user_guid, CONTENT_SUBCRIPTIONS_SUBSCRIPTION, $entity_guid);
	} else {
		// already subscribed
		$result = true;
	}
	
	return $result;
}

/**
 * Automaticly subscribe to the updates of an entity if the user didn't block this
 *
 * @param int $entity_guid the content entity to subscribe to
 * @param int $user_guid   the user to subscribe (defaults to current user)
 *
 * @return bool
 */
function content_subscriptions_autosubscribe($entity_guid, $user_guid = 0) {
	$result = false;
	
	$entity_guid = sanitise_int($entity_guid, false);
	$user_guid = sanitise_int($user_guid, false);
	
	if (empty($user_guid)) {
		$user_guid = elgg_get_logged_in_user_guid();
	}
	
	// check if the user blocked the subscription
	if (!check_entity_relationship($user_guid, CONTENT_SUBCRIPTIONS_BLOCK, $entity_guid)) {
		$entity = get_entity($entity_guid);
		
		// check if this is not the content owner
		if ($entity->getOwnerGUID() != $user_guid) {
			// no, so subscribe
			$result = content_subscriptions_subscribe($entity_guid, $user_guid);
		}
	}
	
	return $result;
}

/**
 * Unsubscribe a user from updates and set a flag so auto updates don't recreate the updates
 *
 * @param int $entity_guid the content entity to unsubscribe from
 * @param int $user_guid   the user to unsubscribe (defaults to current user)
 *
 * @return bool
 */
function content_subscriptions_unsubscribe($entity_guid, $user_guid = 0) {
	$result = false;
	
	$entity_guid = sanitise_int($entity_guid, false);
	$user_guid = sanitise_int($user_guid, false);
	
	if (empty($user_guid)) {
		$user_guid = elgg_get_logged_in_user_guid();
	}
	
	if (!empty($user_guid) && !empty($entity_guid)) {
		// remove subscription
		$result = remove_entity_relationship($user_guid, CONTENT_SUBCRIPTIONS_SUBSCRIPTION, $entity_guid);
		
		// add block subscription
		$result = $result && add_entity_relationship($user_guid, CONTENT_SUBCRIPTIONS_BLOCK, $entity_guid);
	}
	
	return $result;
}

/**
 * Check if the user gets notifications from the group, based on notification settings
 *
 * @param ElggEntity $container the container to check (only act on ElggGroups)
 * @param int        $user_guid the user to check (defaults to current user)
 *
 * @return bool
 */
function content_subscriptions_check_notification_settings(ElggEntity $container, $user_guid = 0) {
	global $NOTIFICATION_HANDLERS;
	
	$result = false;
	
	$user_guid = sanitise_int($user_guid, false);
	
	if (empty($user_guid)) {
		$user_guid = elgg_get_logged_in_user_guid();
	}
	
	// only check groups
	if (!empty($container) && elgg_instanceof($container, "group") && !empty($user_guid)) {
		
		// check all notification handlers, if one is selected return true
		foreach ($NOTIFICATION_HANDLERS as $method => $foo) {
			if (check_entity_relationship($user_guid, "notify" . $method, $container->getGUID())) {
				$result = true;
				break;
			}
		}
	}
	
	return $result;
}

/**
 * Send the notification
 *
 * @param ElggEntity $container the container to check (only act on ElggGroups)
 * @param int        $user_guid the user to check (defaults to current user)
 *
 * @return bool
 */
function content_subscriptions_send_notification($entity, $annotation) {
	global $NOTIFICATION_HANDLERS;
	global $CONFIG;

	$annotation_owner = $annotation->getOwnerEntity();
	$entity_owner = $entity->getOwnerEntity();
	
	// only notify on non private entities
	if ($entity->access_id != ACCESS_PRIVATE) {
		// get interested users
		$options = array(
			"type" => "user",
			"limit" => false,
			"relationship" => CONTENT_SUBCRIPTIONS_SUBSCRIPTION,
			"relationship_guid" => $entity->getGUID(),
			"inverse_relationship" => true,
			"wheres" => array(
				"e.guid <> " . $entity_owner->getGUID(), // owner get notified by other means
				"e.guid <> " . $annotation_owner->getGUID() // don't notify yourself
			)
		);
		
		// exclude group notification subscribers
		$methods = array();
		foreach ($NOTIFICATION_HANDLERS as $method => $foo) {
			$methods[] = "notify" . $method;
		}
		
		$notification_where = "e.guid NOT IN (
				SELECT guid_one
				FROM " . elgg_get_config("dbprefix") . "entity_relationships
				WHERE guid_two = " . $entity->getContainerGUID() . "
				AND relationship IN ('" . implode("", $methods) . "')
		)";
		
		$options["wheres"][] = $notification_where;
		
		// check access limitations
		switch ($entity->access_id) {
			case ACCESS_FRIENDS:
				// this shouldn't happen, so do nothing
				break;
			case ACCESS_LOGGED_IN:
			case ACCESS_PUBLIC:
				// all users are allowed
				break;
			default:
				// this is an ACL
				$acl_members = get_members_of_access_collection($entity->access, true);
				
				if (!empty($acl_members)) {
					$options["wheres"][] = "(e.guid IN (" . implode(",", $acl_members) . "))";
				}
				break;
		}
		
		// proccess users
		$users = new ElggBatch("elgg_get_entities_from_relationship", $options);
		
		foreach ($users as $user) {
			// build message
			$default_subject = $CONFIG->register_objects[$entity->getType()][$entity->getSubtype()];
			$string = $default_subject . ": " . $entity->getURL();
			
			// allow the change of body
			$body = elgg_trigger_plugin_hook("notify:annotation:message", $annotation->getSubtype(), array(
				"annotation" => $annotation,
				"to_entity" => $user,
				"method" => "site"), $string);
			if (empty($body) && ($body !== false)) {
				$body = $string;
			}
			
			// allow the change of subject
			$subject = elgg_trigger_plugin_hook("notify:annotation:subject", $annotation->getSubtype(), array(
				"annotation" => $annotation,
				"to_entity" => $user,
				"method" => "site"), $default_subject);
			if (empty($subject)) {
				$subject = $default_subject;
			}
			
			// send message
			if ($body !== false) {
				notify_user($user->getGUID(), $entity->getContainerGUID(), $subject, $body);
			}
		}
	}
}