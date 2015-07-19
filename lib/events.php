<?php
/**
 * All event handler callback functions are bundled in this file
 */

/**
 * Check this event for the correct object, so subscription notifications can be send out
 *
 * @param string         $event      "create"
 * @param string         $type       "object"
 * @param ElggObject	   $object 		 the created object
 *
 * @return void
 */
function content_subscriptions_create_object_handler($event, $type, ElggObject $object) {
	if (!empty($object)) {
		if (elgg_instanceof($object, 'object', 'answer')) {
			$parent = $object->getContainerEntity();
			content_subscriptions_send_notification($parent, $object);
		} elseif (elgg_instanceof($object, 'object', 'comment')) {
			$parent = $object->getContainerEntity();
			content_subscriptions_send_notification($parent, $object);
		}
	}
}

/**
 * Check this event for the correct annotation, so subscription notifications can be send out
 *
 * @param string         $event      "create"
 * @param string         $type       "annotation"
 * @param ElggAnnotation $annotation the created annotation
 *
 * @return void
 */
function content_subscriptions_create_annotation_handler($event, $type, ElggAnnotation $annotation) {	
	if (!empty($annotation) && ($annotation instanceof ElggAnnotation)) {
		// check for the correct annotations
		switch ($annotation->name) {
			case "group_topic_post":
				$parent = $annotation->getEntity();
				content_subscriptions_send_notification($parent, $annotation);
				break;
		}
	}
}