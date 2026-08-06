<?php

namespace anvildev\booked\migrations;

use craft\db\Migration;

/**
 * Intentionally does nothing. Kept so installs that already applied it are not
 * asked to run something else under this name.
 *
 * It originally added booked_reservations.id as a cascading foreign key onto
 * elements.id, and — because the constraint cannot be added while rows violate
 * it — deleted every reservation with no matching element first.
 *
 * That premise was wrong, and destructively so. A reservation is only a Craft
 * element when Commerce is enabled
 * ({@see \anvildev\booked\factories\ReservationFactory::isElementMode()}). On
 * an install without Commerce *every* reservation is a plain ActiveRecord with
 * nothing in `elements`, so this would have deleted every booking on the site
 * and then added a constraint that made new ones impossible to create.
 *
 * The constraint itself is removed by m260803_000002. Neither ever reached a
 * release: v1.4.6 predates both, so no upgrade path runs the original.
 */
class m260803_000000_reservation_element_fk extends Migration
{
    public function safeUp(): bool
    {
        return true;
    }

    public function safeDown(): bool
    {
        return true;
    }
}
