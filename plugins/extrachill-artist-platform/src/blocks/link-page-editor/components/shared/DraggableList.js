/**
 * DraggableList Component
 *
 * Wraps dnd-kit for drag-and-drop list reordering.
 */

import { useCallback } from '@wordpress/element';
import {
	DndContext,
	closestCenter,
	KeyboardSensor,
	PointerSensor,
	useSensor,
	useSensors,
} from '@dnd-kit/core';
import {
	arrayMove,
	SortableContext,
	sortableKeyboardCoordinates,
	useSortable,
	verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';

function SortableItem( { id, children } ) {
	const {
		attributes,
		listeners,
		setNodeRef,
		transform,
		transition,
		isDragging,
	} = useSortable( { id } );

	const style = {
		transform: CSS.Transform.toString( transform ),
		transition,
		opacity: isDragging ? 0.5 : 1,
	};

	return (
		<div
			ref={ setNodeRef }
			style={ style }
			className={ `ec-draggable ${ isDragging ? 'is-dragging' : '' }` }
			{ ...attributes }
			{ ...listeners }
		>
			{ children }
		</div>
	);
}

export default function DraggableList( { items, onReorder, renderItem } ) {
	const sensors = useSensors(
		useSensor( PointerSensor, {
			activationConstraint: {
				distance: 8,
			},
		} ),
		useSensor( KeyboardSensor, {
			coordinateGetter: sortableKeyboardCoordinates,
		} )
	);

	const handleDragEnd = useCallback(
		( event ) => {
			const { active, over } = event;

			if ( over && active.id !== over.id ) {
				const oldIndex = items.findIndex( ( item ) => item.id === active.id );
				const newIndex = items.findIndex( ( item ) => item.id === over.id );
				const newItems = arrayMove( items, oldIndex, newIndex );
				onReorder( newItems );
			}
		},
		[ items, onReorder ]
	);

	if ( ! items || items.length === 0 ) {
		return null;
	}

	const itemIds = items.map( ( item ) => item.id );

	return (
		<DndContext
			sensors={ sensors }
			collisionDetection={ closestCenter }
			onDragEnd={ handleDragEnd }
		>
			<SortableContext items={ itemIds } strategy={ verticalListSortingStrategy }>
				<div className="ec-draggable-list">
					{ items.map( ( item, index ) => (
						<SortableItem key={ item.id } id={ item.id }>
							{ renderItem( item, index ) }
						</SortableItem>
					) ) }
				</div>
			</SortableContext>
		</DndContext>
	);
}
