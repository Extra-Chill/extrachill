<?php
/**
 * Priority Boost order item test double.
 *
 * @package ExtraChillShop
 */

final class PriorityBoostTestItem {
	private $id;
	private $event_id;
	private $event_reference;
	private $event_title;

	public function __construct( $id, $event_id, $event_reference, $event_title ) {
		$this->id              = $id;
		$this->event_id        = $event_id;
		$this->event_reference = $event_reference;
		$this->event_title     = $event_title;
	}

	public function get_product_id() {
		return 99;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_meta( $key ) {
		$values = array(
			'priority_boost_event_id'        => $this->event_id,
			'priority_boost_event_reference' => $this->event_reference,
			'priority_boost_event_title'     => $this->event_title,
		);
		return $values[ $key ] ?? '';
	}
}
