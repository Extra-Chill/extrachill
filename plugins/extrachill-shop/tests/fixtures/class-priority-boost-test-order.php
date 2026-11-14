<?php
/**
 * Priority Boost order test double.
 *
 * @package ExtraChillShop
 */

final class PriorityBoostTestOrder {
	private $id;
	private $items;
	private $paid;
	public $meta       = array();
	public $notes      = array();
	public $save_count = 0;
	public $status     = 'completed';

	public function __construct( $id, array $items, $paid = true ) {
		$this->id    = $id;
		$this->items = $items;
		$this->paid  = (bool) $paid;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_items() {
		return $this->items;
	}

	public function is_paid() {
		return $this->paid;
	}

	public function get_status() {
		return $this->status;
	}

	public function get_meta( $key ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function add_order_note( $note ) {
		$this->notes[] = $note;
	}

	public function save() {
		++$this->save_count;
	}
}
