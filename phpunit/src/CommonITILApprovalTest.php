<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * http://glpi-project.org
 *
 * @copyright 2015-2025 Teclib' and contributors.
 * @licence   https://www.gnu.org/licenses/gpl-3.0.html
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * ---------------------------------------------------------------------
 */

namespace Glpi\PHPUnit\Tests;

use CommonITILObject;
use CommonITILValidation;
use DbTestCase;
use Document_Item;
use Glpi\PHPUnit\Tests\Glpi\ValidationStepTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Rule;
use RuntimeException;
use Ticket;
use TicketValidation;
use TicketValidationStep;
use User;

/* Test for inc/commonitilvalidation.class.php */

abstract class CommonITILApprovalTest extends DbTestCase
{
    use ValidationStepTrait;

    /**
     * Tested classname (eg. TicketValidation, ChangeValidation, ...)
     * @return class-string<\CommonITILValidation>
     */
    protected function getValidationClassname(): string
    {
        $test_class = str_replace('Approval', 'Validation', static::class);

        // Rule class has the same name as the test class but in the global namespace
        return preg_replace('/Test$/', '', substr(strrchr($test_class, '\\'), 1));
    }

    /**
     * ITIL object classname (eg. Ticket, Change, ...)
     *
     * @return class-string<\CommonITILObject>
     */
    protected function getITILClassname(): string
    {
        $tested_class = $this->getValidationClassname();
        return str_replace('Validation', '', $tested_class);
    }

    /**
     * ITILValidationStep classname (eg. TicketValidationStep, ChangeValidationStep, ...)
     *
     * @return class-string<\ITIL_ValidationStep>
     */
    protected function getITILValidationStepClassname(): string
    {
        return $this->getITILClassname() . 'ValidationStep';
    }

    public function testCanValidateUser()
    {
        $this->login();
        $default_validation_step_id = $this->getInitialDefaultValidationStep()->getID();
        $itil_class = $this->getITILClassname();
        $validation_class = $this->getValidationClassname();

        // Create fist ITIL item
        $itil_1 = $this->createItem($this->getITILClassname(), [
            'name'      => __FUNCTION__,
            'content'   => __FUNCTION__,
        ]);
        $itil_1_id = $itil_1->getID();

        // Test the current user cannot approve since there are no approvals
        $this->assertFalse($validation_class::canValidate($itil_1_id));

        // 1 --- User can validate its own approval

        // create itil_validationstep
        $validationstep_classname = $itil_class::getValidationStepClassName();
        $itils_validationsteps = $this->createItem($validationstep_classname, ['validationsteps_id' => $default_validation_step_id, 'minimal_required_validation_percent' => 100]);

        $validation_1 = $this->createItem($validation_class, [
            $itil_class::getForeignKeyField()   => $itil_1_id,
            'itemtype_target'                   => 'User',
            'items_id_target'                   => $_SESSION['glpiID'],
            'comment_submission'                => __FUNCTION__,
            'itils_validationsteps_id'          => $itils_validationsteps->getID(),
        ]);

        $this->assertTrue($validation_class::canValidate($itil_1_id));

        // 2 --- User cannot validate other user approval

        // Add user approval for other user
        $validation_2 = new $validation_class();
        $this->createItem($validation_class, [
            $itil_class::getForeignKeyField()   => $itil_1_id,
            'itemtype_target'                   => 'User',
            'items_id_target'                   => User::getIdByName('normal'), // Other user, not the logged in one
            'comment_submission'                => __FUNCTION__,
            'itils_validationsteps_id'          => $itils_validationsteps->getID()
        ]);

        // - Test the current user can still approve fist approval
        $this->assertTrue($validation_class::canValidate($itil_1_id));

        // Test the current user can answer approve their own approval
        $this->assertTrue($validation_1->canAnswer());

        // Test the current user cannot approve the other user's approval
        $this->assertFalse($validation_2->canAnswer());

        // Remove user approval for current user
        $this->assertTrue($validation_1->delete(['id' => $validation_1->getID()]));

        // Test the current user cannot still approve since the remaining approval isn't for them
        $this->assertFalse($validation_class::canValidate($itil_1_id));

        // Test the current user, as a substitute of the validator, can approve
        // without substitution period
        $validator_substitute = new \ValidatorSubstitute();
        $validator_substitute->add([
            'users_id' => User::getIdByName('normal'),
            'users_id_substitute' => $_SESSION['glpiID'],
        ]);
        $this->assertFalse($validator_substitute->isNewItem());
        $other_user = new User();
        $other_user->getFromDBbyName('normal');
        $other_user->update([
            'id' => $other_user->getID(),
            'substitution_start_date' => 'NULL',
            'substitution_end_date' => 'NULL',
        ]);
        $this->assertTrue($validation_class::canValidate($itil_1_id));

        // Test the current user, as a substitute of the validator, can approve
        // with substitution period start date only
        $other_user->getFromDBbyName('normal');
        $other_user->update([
            'id' => $other_user->getID(),
            'substitution_start_date' => '2021-01-01 00:00:00',
            'substitution_end_date' => 'NULL',
        ]);
        $this->assertTrue($validation_class::canValidate($itil_1_id));

        // Test the current user, as a substitute of the validator, can approve
        // with substitution period start date only excluding now
        $other_user->getFromDBbyName('normal');
        $other_user->update([
            'id' => $other_user->getID(),
            'substitution_start_date' => (new \DateTime())->modify("+1 month")->format("Y-m-d h:i:s"),
            'substitution_end_date' => 'NULL',
        ]);
        $this->assertFalse($validation_class::canValidate($itil_1_id));

        // Test the current user, as a substitute of the validator, can approve
        // with substitution period end date only
        $other_user->getFromDBbyName('normal');
        $other_user->update([
            'id' => $other_user->getID(),
            'substitution_start_date' => 'NULL',
            'substitution_end_date' => (new \DateTime())->modify("+1 month")->format("Y-m-d h:i:s"),
        ]);
        $this->assertTrue($validation_class::canValidate($itil_1_id));

        // Test the current user, as a substitute of the validator, can approve
        // with substitution period end date only excluding now
        $other_user->getFromDBbyName('normal');
        $other_user->update([
            'id' => $other_user->getID(),
            'substitution_start_date' => 'NULL',
            'substitution_end_date' => '2021-01-01 00:00:00',
        ]);
        $this->assertFalse($validation_class::canValidate($itil_1_id));

        // Test the current user, as a substitute of the validator, can approve
        // with substitution period
        $other_user->getFromDBbyName('normal');
        $other_user->update([
            'id' => $other_user->getID(),
            'substitution_start_date' => '2021-01-01 00:00:00',
            'substitution_end_date' => (new \DateTime())->modify("+1 month")->format("Y-m-d h:i:s"),
        ]);
        $this->assertTrue($validation_class::canValidate($itil_1_id));

        // Test the current user, as a substitute of the validator, can approve
        // with substitution period
        $other_user->getFromDBbyName('normal');
        $other_user->update([
            'id' => $other_user->getID(),
            'substitution_start_date' => '2021-01-01 00:00:00',
            'substitution_end_date' => (new \DateTime())->modify("-1 month")->format("Y-m-d h:i:s"),
        ]);
        $this->assertFalse($validation_class::canValidate($itil_1_id));
    }

    public function testCanValidateGroup()
    {
        $this->login();
        $default_validation_step_id = $this->getInitialDefaultValidationStep()->getID();

        $itil_class = $this->getITILClassname();
        $itil_item = new $itil_class();
        $itil_items_id = $itil_item->add([
            'name'      => __FUNCTION__,
            'content'   => __FUNCTION__,
        ]);
        $this->assertGreaterThan(0, $itil_items_id);

        $validation_class = $this->getValidationClassname();
        $validation = new $validation_class();

        // Test the current user cannot approve since there are no approvals
        $this->assertFalse($validation::canValidate($itil_items_id));

        // Create a test group
        $group = new \Group();
        $groups_id = $group->add([
            'name' => __FUNCTION__ . ' group',
        ]);
        $this->assertGreaterThan(0, $groups_id);

        $other_group = new \Group();
        $other_groups_id = $group->add([
            'name' => __FUNCTION__ . ' other group',
        ]);
        $this->assertGreaterThan(0, $other_groups_id);

        // Add current user to the group
        $group_user = new \Group_User();
        $this->assertGreaterThan(
            0,
            $group_user->add([
                'groups_id' => $groups_id,
                'users_id'  => $_SESSION['glpiID'],
            ])
        );

        // Add approval for user's group
        $validation_id_1_data = [
            $itil_class::getForeignKeyField() => $itil_items_id,
            'itemtype_target' => 'Group',
            'items_id_target' => $groups_id,
            'comment_submission' => __FUNCTION__,
        ];
        // create itil_validationstep
        $validationstep_classname = $itil_class::getValidationStepClassName();
        $itils_validationsteps = $this->createItem($validationstep_classname, ['validationsteps_id' => $default_validation_step_id, 'minimal_required_validation_percent' => 100]);
        $validation_id_1_data['itils_validationsteps_id'] = $itils_validationsteps->getID();

        $validations_id_1 = $this->createItem($validation_class, $validation_id_1_data)->getID();

        $this->assertTrue($validation::canValidate($itil_items_id));

        // Add approval for other group
        $approval_data = [
            $itil_class::getForeignKeyField() => $itil_items_id,
            'itemtype_target' => 'Group',
            'items_id_target' => $other_groups_id, // Other group.
            'comment_submission' => __FUNCTION__,
            'itils_validationsteps_id' => $itils_validationsteps->getID()
        ];
        $this->createItem($validation_class, $approval_data)->getID();

        // Test the current user can still approve since they still have an approval
        $this->assertTrue($validation::canValidate($itil_items_id));
        // Remove approval for current user's group
        $this->assertTrue($validation->delete(['id' => $validations_id_1]));
        // Test the current user cannot still approve since the remaining approval isn't for them
        $this->assertFalse($validation::canValidate($itil_items_id));

        // Add normal user to the other group
        $group_user = new \Group_User();
        $this->assertGreaterThan(
            0,
            $group_user->add([
                'groups_id' => $other_groups_id,
                'users_id'  => User::getIdByName('normal'),
            ])
        );

        // Add current user as a substitute of normal (member of other group)
        $validator_substitute = new \ValidatorSubstitute();
        $validator_substitute->add([
            'users_id' => User::getIdByName('normal'),
            'users_id_substitute' => $_SESSION['glpiID'],
        ]);
        $this->assertFalse($validator_substitute->isNewItem());
        $other_user = new User();
        $other_user->getFromDBbyName('normal');
        $other_user->update([
            'id' => $other_user->getID(),
            'substitution_start_date' => 'NULL',
            'substitution_end_date' => 'NULL',
        ]);
        $this->assertTrue($validation::canValidate($itil_items_id));

        // Test the current user, as a substitute of the validator, can approve
        // with substitution period start date only
        $other_user->getFromDBbyName('normal');
        $other_user->update([
            'id' => $other_user->getID(),
            'substitution_start_date' => '2021-01-01 00:00:00',
            'substitution_end_date' => 'NULL',
        ]);
        $this->assertTrue($validation::canValidate($itil_items_id));

        // Test the current user, as a substitute of the validator, can approve
        // with substitution period start date only excluding now
        $other_user->getFromDBbyName('normal');
        $other_user->update([
            'id' => $other_user->getID(),
            'substitution_start_date' => (new \DateTime())->modify("+1 month")->format("Y-m-d h:i:s"),
            'substitution_end_date' => 'NULL',
        ]);
        $this->assertFalse($validation::canValidate($itil_items_id));

        // Test the current user, as a substitute of the validator, can approve
        // with substitution period end date only
        $other_user->getFromDBbyName('normal');
        $other_user->update([
            'id' => $other_user->getID(),
            'substitution_start_date' => 'NULL',
            'substitution_end_date' => (new \DateTime())->modify("+1 month")->format("Y-m-d h:i:s"),
        ]);
        $this->assertTrue($validation::canValidate($itil_items_id));

        // Test the current user, as a substitute of the validator, can approve
        // with substitution period end date only excluding now
        $other_user->getFromDBbyName('normal');
        $other_user->update([
            'id' => $other_user->getID(),
            'substitution_start_date' => 'NULL',
            'substitution_end_date' => '2021-01-01 00:00:00',
        ]);
        $this->assertFalse($validation::canValidate($itil_items_id));

        // Test the current user, as a substitute of the validator, can approve
        // with substitution period
        $other_user->getFromDBbyName('normal');
        $other_user->update([
            'id' => $other_user->getID(),
            'substitution_start_date' => '2021-01-01 00:00:00',
            'substitution_end_date' => (new \DateTime())->modify("+1 month")->format("Y-m-d h:i:s"),
        ]);
        $this->assertTrue($validation::canValidate($itil_items_id));

        // Test the current user, as a substitute of the validator, can approve
        // with substitution period
        $other_user->getFromDBbyName('normal');
        $other_user->update([
            'id' => $other_user->getID(),
            'substitution_start_date' => '2021-01-01 00:00:00',
            'substitution_end_date' => (new \DateTime())->modify("-1 month")->format("Y-m-d h:i:s"),
        ]);
        $this->assertFalse($validation::canValidate($itil_items_id));
    }

    public static function prepareInputForAddProvider()
    {
        $validationsteps_id = 1;

        $user_validations = [
            [
                'input' => [
                    'itemtype_target' => 'User',
                    'items_id_target' => 1,
                    'comment_submission' => 'test',
                    '%FK_FIELD%' => 1,
                    'validationsteps_id' => $validationsteps_id,
                ],
                'expected' => [
                    'itemtype_target' => 'User',
                    'items_id_target' => 1,
                    'comment_submission' => 'test',
                    'status' => CommonITILValidation::WAITING,
                    'validationsteps_id' => $validationsteps_id,
                ],
            ],
            [
                'input' => [ // Invalid input
                    'itemtype_target' => 'User',
                    'validationsteps_id' => $validationsteps_id,
                ],
                'expected' => [],
            ],
            [
                'input' => [ // Invalid input
                    'itemtype_target' => 'User',
                    'items_id_target' => -1,
                    'validationsteps_id' => $validationsteps_id,
                ],
                'expected' => [],
            ],
        ];

        $group_validations = [
            [
                'input' => [
                    'itemtype_target' => 'Group',
                    'items_id_target' => 1,
                    'comment_submission' => 'test',
                    '%FK_FIELD%' => 1,
                    'validationsteps_id' => $validationsteps_id,
                ],
                'expected' => [
                    'itemtype_target' => 'Group',
                    'items_id_target' => 1,
                    'comment_submission' => 'test',
                    'status' => CommonITILValidation::WAITING,
                    'validationsteps_id' => $validationsteps_id,
                ],
                'input_blocklist' => [
                    'users_id_validate',
                ],
            ],
            [
                'input' => [ // Invalid input
                    'itemtype_target' => 'Group',
                    'validationsteps_id' => $validationsteps_id,
                ],
                'expected' => [],
            ],
        ];

        return array_merge($user_validations, $group_validations);
    }

    #[DataProvider('prepareInputForAddProvider')]
    public function testPrepareInputForAdd(array $input, array $expected, array $input_blocklist = [])
    {
        $this->login();

        if (isset($input['%FK_FIELD%'])) {
            $fk_field = $this->getITILClassname()::getForeignKeyField();
            $input[$fk_field] = $input['%FK_FIELD%'];
            unset($input['%FK_FIELD%']);
        }

        $validation_class = $this->getValidationClassname();
        /** @var \CommonITILValidation $validation */
        $validation = new $validation_class();
        //$validation::$mustBeAttached = false;

        if (!empty($expected)) {
            $result = $validation->prepareInputForAdd($input);
            foreach (array_keys($expected) as $key) {
                $this->assertArrayHasKey($key, $result);
            }
            if (!empty($input_blocklist)) {
                foreach ($input_blocklist as $key) {
                    $this->assertArrayNotHasKey($key, $result);
                }
            }
            foreach ($result as $k => $v) {
                if (isset($expected[$k])) {
                    $this->assertEquals($expected[$k], $v);
                }
            }
        } else {
            $this->assertFalse($validation->prepareInputForAdd($input));
        }
    }

    public static function prepareInputForUpdateProvider()
    {
        $validationsteps_id = 1;

        return [
            [
                'input' => [
                    'status' => CommonITILValidation::WAITING,
                    'itemtype_target' => 'User',
                    'items_id_target' => '_CURRENT_USER_',
                    'validationsteps_id' => $validationsteps_id,
                ],
                'expected' => [
                    'status' => CommonITILValidation::WAITING,
                    'validation_date' => 'NULL',
                    'validationsteps_id' => $validationsteps_id,
                ],
            ],
            [
                'input' => [
                    'status' => CommonITILValidation::ACCEPTED,
                    'validation_date' => $_SESSION["glpi_currenttime"],
                    'itemtype_target' => 'User',
                    'items_id_target' => '_CURRENT_USER_',
                    'validationsteps_id' => $validationsteps_id,
                ],
                'expected' => [
                    'status' => CommonITILValidation::ACCEPTED,
                    'validation_date' => '_CURRENT_TIME_',
                    'validationsteps_id' => $validationsteps_id,
                ],
            ]
        ];
    }

    #[DataProvider('prepareInputForUpdateProvider')]
    public function testPrepareInputForUpdate(array $input, array $expected)
    {
        $this->login();

        $validation_class = $this->getValidationClassname();
        $validation = new $validation_class();
        //$validation::$mustBeAttached = false;

        // Replace placeholders
        $arrays = [&$input, &$expected];
        foreach ($arrays as &$array) {
            foreach ($array as $k => $v) {
                // Using placeholder for current user as the session is started after the data in retrieved from the provider
                if ($v === '_CURRENT_USER_') {
                    $array[$k] = \Session::getLoginUserID();
                } else if ($v === '_CURRENT_TIME_') {
                    $array[$k] = $_SESSION["glpi_currenttime"];
                }
            }
        }
        unset($array);
        $itilObject = new $validation::$itemtype();
        $itilObject->getFromDb(1);
        $this->assertFalse($itilObject->isNewItem());
        $validation->add([
            'users_id' => \Session::getLoginUserID(),
            $validation::$items_id => $itilObject->getID(),
            'users_id_validate' => \Session::getLoginUserID(),
            'itemtype_target' => User::class,
            'items_id_target' => \Session::getLoginUserID(),
            'status' => $input['status'],
            'timeline_position' => '1',
            'validationsteps_id' => $input['validationsteps_id'],
        ]);
        $this->assertFalse($validation->isNewItem());
        if (!empty($expected)) {
            $result = $validation->prepareInputForUpdate($input);
            foreach (array_keys($expected) as $key) {
                $this->assertArrayHasKey($key, $result);
            }
            foreach ($result as $k => $v) {
                if (isset($expected[$k])) {
                    $this->assertEquals($expected[$k], $v);
                }
            }
        } else {
            $this->assertFalse($validation->prepareInputForUpdate($input));
        }
    }

    public function testPrepareInputForUpdateNotMineToAnswer()
    {
        $this->login();

        /** @var class-string<\CommonITILValidation> $validation_class */
        $validation_class = $this->getValidationClassname();
        $itilobject = new ($validation_class::$itemtype)();
        $itil_id = $itilobject->add([
            'name' => __FUNCTION__,
            'content' => __FUNCTION__,
            'entities_id' => $this->getTestRootEntity(true),
        ]);
        $validation = new $validation_class();
        $notmine_validation = $validation->add([
            $validation::$items_id => $itil_id,
            'itemtype_target' => 'User',
            'items_id_target' => \User::getIdByName('normal'),
            'status' => \TicketValidation::WAITING,
        ]);
        $validation->getFromDB($notmine_validation);
        $input = $validation->prepareInputForUpdate([
            'status' => \CommonITILValidation::ACCEPTED,
            'comment_validation' => 'test',
        ]);
        $this->assertEmpty($input);
    }

    public static function getHistoryChangeWhenUpdateFieldProvider()
    {
        return [
            [
                'fields'    => [
                    'users_id_validate' => getItemByTypeName('User', TU_USER, true),
                    'status' => CommonITILValidation::ACCEPTED,
                ],
                'field'    => 'status',
                'expected' => ['0', '', sprintf(__('Approval granted by %s'), TU_USER)],
            ],
            [
                'fields'    => [
                    'itemtype_target' => 'User',
                    'items_id_target' => getItemByTypeName('User', TU_USER, true),
                    'status' => CommonITILValidation::REFUSED,
                ],
                'field'    => 'status',
                'expected' => ['0', '', sprintf(__('Update the approval request to %s'), TU_USER)],
            ],
            [
                'fields'    => [
                    'itemtype_target' => 'Group',
                    'items_id_target' => getItemByTypeName('Group', '_test_group_1', true),
                    'status' => CommonITILValidation::REFUSED,
                ],
                'field'    => 'status',
                'expected' => ['0', '', sprintf(__('Update the approval request to %s'), '_test_group_1')],
            ],
            [
                'fields'    => [
                    'itemtype_target' => 'Group',
                    'items_id_target' => getItemByTypeName('Group', '_test_group_1', true),
                    'status' => CommonITILValidation::REFUSED,
                ],
                'field'    => 'validation_comment',
                'expected' => [],
            ]
        ];
    }

    #[DataProvider('getHistoryChangeWhenUpdateFieldProvider')]
    public function testGetHistoryChangeWhenUpdateField(array $fields, string $field, array $expected)
    {
        $validation_class = $this->getValidationClassname();
        $validation = new $validation_class();

        $validation->fields = array_merge($validation->fields, $fields);
        $this->assertSame($expected, $validation->getHistoryChangeWhenUpdateField($field));
    }

    public static function getHistoryNameForItemProvider()
    {
        return [
            [
                'fields'    => [
                    'itemtype_target' => 'User',
                    'items_id_target' => getItemByTypeName('User', TU_USER, true),
                ],
                'case'    => 'add',
                'expected' => sprintf(__('Approval request sent to %s'), TU_USER),
            ],
            [
                'fields'    => [
                    'itemtype_target' => 'Group',
                    'items_id_target' => getItemByTypeName('Group', '_test_group_1', true),
                ],
                'case'    => 'add',
                'expected' => sprintf(__('Approval request sent to %s'), '_test_group_1'),
            ],
            [
                'fields'    => [
                    'itemtype_target' => 'User',
                    'items_id_target' => getItemByTypeName('User', TU_USER, true),
                ],
                'case'    => 'delete',
                'expected' => sprintf(__('Cancel the approval request to %s'), TU_USER),
            ],
            [
                'fields'    => [
                    'itemtype_target' => 'Group',
                    'items_id_target' => getItemByTypeName('Group', '_test_group_1', true),
                ],
                'case'    => 'delete',
                'expected' => sprintf(__('Cancel the approval request to %s'), '_test_group_1'),
            ]
        ];
    }

    #[DataProvider('getHistoryNameForItemProvider')]
    public function testGetHistoryNameForItem(array $fields, string $case, string $expected)
    {
        $validation_class = $this->getValidationClassname();
        $validation = new $validation_class();

        $validation->fields = array_merge($validation->fields, $fields);
        $this->assertSame($expected, $validation->getHistoryNameForItem($validation, $case));
    }

    public function testCreateValidation()
    {
        $validation_class = $this->getValidationClassname();
        $validation = new $validation_class();
        $default_validation_step_id = $this->getInitialDefaultValidationStep()->getID();

        $user = new User();
        $user->add([
            'name' => __FUNCTION__,
            'password' => __FUNCTION__,
            'password2' => __FUNCTION__,
        ]);
        $group = $this->createItem('Group', ['name' => __FUNCTION__]);
        $this->createItem('Group_User', ['users_id' => $user->getID(), 'groups_id' => $group->getID()]);

        $this->login(__FUNCTION__, __FUNCTION__);

        $itil_class = $this->getITILClassname();

        /** @var \CommonITILObject $itil_item */
        $itil_item = $this->createItem($itil_class, [
            'name' => __FUNCTION__,
            'content' => __FUNCTION__,
            'entities_id' => getItemByTypeName('Entity', '_test_root_entity', true),
            'status' => CommonITILObject::INCOMING,
        ]);

        $validations_id = $validation->add([
            $itil_class::getForeignKeyField() => $itil_item->getID(),
            'itemtype_target' => 'User',
            'items_id_target' => $user->getID(),
            'status' => CommonITILValidation::WAITING,
            'users_id' => 1,
            'validationsteps_id' => $default_validation_step_id,
        ]);
        $this->assertGreaterThan(0, (int) $validations_id);
        $this->assertEquals(1, $validation_class::getNumberToValidate($user->getID()));

        $itil_item_2 = $this->createItem($itil_class, [
            'name' => __FUNCTION__ . '2',
            'content' => __FUNCTION__ . '2',
            'entities_id' => getItemByTypeName('Entity', '_test_root_entity', true),
            'status' => CommonITILObject::INCOMING,
        ]);

        $validations_id = $validation->add([
            $itil_class::getForeignKeyField() => $itil_item_2->getID(),
            'itemtype_target' => 'Group',
            'items_id_target' => $group->getID(),
            'status' => CommonITILValidation::WAITING,
            'users_id' => 1,
            'validationsteps_id' => $default_validation_step_id,
        ]);
        $this->assertGreaterThan(0, (int) $validations_id);
        $this->assertEquals(2, $validation_class::getNumberToValidate($user->getID()));

        $validation->update([
            'id' => $validations_id,
            'status' => CommonITILValidation::ACCEPTED
        ]);
        $this->assertEquals(1, $validation_class::getNumberToValidate($user->getID()));
    }

    public function testGetCanValidationStatusArray()
    {
        $validation_class = $this->getValidationClassname();
        $validation = new $validation_class();

        $this->assertContains(CommonITILValidation::NONE, $validation->getCanValidationStatusArray());
        $this->assertContains(CommonITILValidation::ACCEPTED, $validation->getCanValidationStatusArray());
    }

    public function testGetAllValidationStatusArray()
    {
        $validation_class = $this->getValidationClassname();
        $validation = new $validation_class();

        $this->assertContains(CommonITILValidation::NONE, $validation->getAllValidationStatusArray());
        $this->assertContains(CommonITILValidation::WAITING, $validation->getAllValidationStatusArray());
        $this->assertContains(CommonITILValidation::REFUSED, $validation->getAllValidationStatusArray());
        $this->assertContains(CommonITILValidation::ACCEPTED, $validation->getAllValidationStatusArray());
    }


    /**
     * @todo Split in multilple tests (hard to understand and maintain) (multiple tickets, test dependent on previous tests actions)
     * @todo this test acts only on Ticket but should also act on all ItilValidations
     * - create a user group, add 2 users in this group
     * - create a rule on ticket creation, this rules is triggered if ticket is assigned to the created group, it creates a validation request
     * - create a ticket, not assign to the group -> no validation created
     * - create a ticket, assign it to the group -> validation request is created, it's status is WAITING
     * - ...
     */
    public function testGroupUserApproval(): void
    {
        $this->login();

        // --- Arrange

        /** Create a group with two users (existing one () and new ($user_approval) */
        $group_1 = $this->createItem('Group', [            'name'   => 'Test group'        ]);
        $group_1_id = (int)$group_1->getID();

        $user_approval = $this->createItem(User::class, [
            'name'      => 'approval',
            'password'  => 'approval',
            'password2' => 'approval'
        ], ['password', 'password2']);

        // set new user with admin profile
        $this->createItem(\Profile_User::class, [
            'users_id'     => $user_approval->getID(),
            'profiles_id'  => getItemByTypeName('Profile', 'admin', true),
            'entities_id'  => 0
        ]);

        // set new user in group 1
        $this->createItem('Group_User', [
            'groups_id' => $group_1->getID(),
            'users_id'  => $user_approval->getID(),
        ]);

        // set existing user in group 1
        $user_glpi = getItemByTypeName('User', 'glpi');
        $this->createItem('Group_User', [
            'groups_id' => $group_1->getID(),
            'users_id'  => $user_glpi->getID(),
        ]);

        /** Create a rule on ticket
         * - on creation and update
         * - condition : ticket is assigned to group 1
         * - action : add a validation request for group 1
         */
        $ruleticket = new \RuleTicket();
        $rulecrit = new \RuleCriteria();
        $condition = \RuleTicket::ONUPDATE + \RuleTicket::ONADD;
        $ruleaction = new \RuleAction();

        // create rule
        $ruletid = $ruleticket->add($ruletinput = [
            'name' => "test rule add",
            'match' => 'AND',
            'is_active' => 1,
            'sub_type' => 'RuleTicket',
            'condition' => $condition,
            'is_recursive' => 1
        ]);
        $this->checkInput($ruleticket, $ruletid, $ruletinput);

        // add criteria to rule
        $crit_id = $rulecrit->add($crit_input = [
            'rules_id' => $ruletid,
            'criteria' => '_groups_id_assign',
            'condition' => Rule::PATTERN_IS,
            'pattern' => $group_1_id
        ]);
        $this->checkInput($rulecrit, $crit_id, $crit_input);

        // add action to rule
        $act_id = $ruleaction->add($act_input = [
            'rules_id' => $ruletid,
            'action_type' => 'add_validation',
            'field' => 'groups_id_validate',
            'value' => $group_1_id
        ]);
        $this->checkInput($ruleaction, $act_id, $act_input);

        // --- Act

        // --- Test 1 : Create a ticket that will not trigger the rule : test that no approval requested created
        $ticket_1 = $this->createItem(Ticket::class, [
            'name' => "test ticket, will not trigger on rule",
            'content' => "test",
        ]);

        $this->assertEquals(CommonITILValidation::NONE, (int)$ticket_1->fields['global_validation']);
        $this->assertEquals(
            0,
            countElementsInTable(
                TicketValidation::getTable(),
                ['tickets_id' => $ticket_1->getID()]
            )
        );

        // --- Test 2 : Create a ticket that will trigger the rule : test that an approval request is created */
        $ticket_2 = $this->createItem(Ticket::class, [
            'name' => "test ticket, approval will be added",
            'content' => "test",
            '_groups_id_assign' => $group_1->getID()
        ]);

        // one validation for each user in group 1 (user_glpi and user_approval)
        $this->assertEquals(
            2,
            countElementsInTable(
                TicketValidation::getTable(),
                ['tickets_id' => $ticket_2->getID()]
            )
        );

        $this->assertEquals(CommonITILValidation::WAITING, (int)$ticket_2->fields['global_validation']);

        // --- Test 3 : update ticket_1 trigger rule : test that 2 approval request are created */
        $ticket_1 = $this->updateItem(Ticket::class, $ticket_1->getID(), [
            'name' => 'test ticket, approval will be also added',
            '_itil_assign' => ['_type' => 'group', 'groups_id' => $group_1->getID()],
        ]);

        $this->assertEquals(
            2,
            countElementsInTable(
                TicketValidation::getTable(),
                ['tickets_id' => $ticket_1->getID()]
            )
        );

        $this->assertValidationStatusEquals(CommonITILValidation::WAITING, (int)$ticket_1->fields['global_validation']);

        // accept first validation - implies that validation required is at 0%
        $this->login('glpi', 'glpi');

        $validation_glpi = new TicketValidation();
        $this->assertTrue(
            $validation_glpi->getFromDBByCrit([
                'tickets_id' => $ticket_1->getID(),
                'itemtype_target' => 'User',
                'items_id_target' => $user_glpi->getID(),
            ])
        );

        // update itil_validation step to require 0%, so the first validation ACCEPTED will cause the ticket global_validation to be ACCEPTED
        // update validation step of ticket 1 validation to require 0% for the first validation
        $this->updateItem(
            TicketValidationStep::class,
            $validation_glpi->fields['itils_validationsteps_id'],
            ['minimal_required_validation_percent' => 0]
        );

        // update created validation status to ACCEPTED
        $this->assertTrue(
            $validation_glpi->update([
                'id' => $validation_glpi->fields['id'],
                'status' => CommonITILValidation::ACCEPTED
            ])
        );

        $this->assertTrue($ticket_1->getFromDB($ticket_1->getID()));
        $this->assertValidationStatusEquals(CommonITILValidation::ACCEPTED, (int)$ticket_1->fields['global_validation']);

        // --- Test : refuse other one fails because of missing comment
        $this->login('approval', 'approval');

        $validation_approval = new TicketValidation();
        $this->assertTrue(
            $validation_approval->getFromDBByCrit([
                'tickets_id' => $ticket_1->getID(),
                'itemtype_target' => 'User',
                'items_id_target' => $user_approval->getID(),
            ])
        );

        $res = $validation_approval->update([
            'id' => $validation_approval->fields['id'],
            'status' => CommonITILValidation::REFUSED
        ]);
        $this->assertFalse($res);
        $this->hasSessionMessages(ERROR, ['If approval is denied, specify a reason.']);

        // retry with comment / img paste and doc upload on ticket 2
        // Test : document upload and status change

        // update ticket 2 validation step to require 100%
        $this->updateItem(
            TicketValidationStep::class,
            $validation_approval->fields['itils_validationsteps_id'],
            ['minimal_required_validation_percent' => 100]
        );

        $base64Image = base64_encode(file_get_contents(FIXTURE_DIR . '/uploads/foo.png'));
        $filename_img = '5e5e92ffd9bd91.11111111image_paste22222222.png';
        $filename_txt = '5e5e92ffd9bd91.11111111' . 'foo.txt';
        copy(FIXTURE_DIR . '/uploads/foo.png', GLPI_TMP_DIR . '/' . $filename_img);
        copy(FIXTURE_DIR . '/uploads/foo.txt', GLPI_TMP_DIR . '/' . $filename_txt);
        $this->updateItem(
            TicketValidation::class,
            $validation_approval->getID(),
            [
                'id' => $validation_approval->fields['id'],
                'tickets_id' => $ticket_1->getID(),
                'status' => CommonITILValidation::REFUSED,
                'comment_validation' => 'Meh &lt;p&gt; &lt;/p&gt;&lt;p&gt;&lt;img id="3e29dffe-0237ea21-5e5e7034b1d1a1.00000000"'
                    . ' src="data:image/png;base64,' . $base64Image . '" width="12" height="12" /&gt;&lt;/p&gt;',
                '_filename' => [
                    $filename_img,
                    $filename_txt
                ],
                '_tag_filename' => [
                    '3e29dffe-0237ea21-5e5e7034b1d1a1.00000000',
                    '3e29dffe-0237ea21-5e5e7034b1ffff.00000000',
                ],
                '_prefix_filename' => [
                    '5e5e92ffd9bd91.11111111',
                    '5e5e92ffd9bd91.11111111',
                ]
            ],
            ['comment_validation'] // contents are changed before db update
        );

        // check document upload
        $this->assertEquals(
            2,
            countElementsInTable(
                Document_Item::getTable(),
                ['itemtype' =>  TicketValidation::getType()]
            )
        );

        $this->assertTrue($ticket_1->getFromDB($ticket_1->getID()));

        // there is now one refused validation and a waiting one, validation_step requirement is 0%, so ticket global_validation is REFUSED
        $this->assertValidationStatusEquals(CommonITILValidation::REFUSED, (int)$ticket_1->fields['global_validation']);

        // require 100% for global status to be changed
        assert(100 === $this->getInitialDefaultValidationStep()->fields['minimal_required_validation_percent']);
        /** Create a ticket, approval requested */
        $ticket = new Ticket();
        $tickets_id_2 = $ticket->add($ticket_input = [
            'name' => "test ticket, approval will be added",
            'content' => "test",
            '_groups_id_assign' => $group_1_id,
        ]);
        unset($ticket_input['_groups_id_assign']);
        $this->checkInput($ticket, $tickets_id_2, $ticket_input);

        $this->assertEquals(
            2,
            countElementsInTable(
                TicketValidation::getTable(),
                ['tickets_id' => $tickets_id_2]
            )
        );

        $this->assertValidationStatusEquals(CommonITILValidation::WAITING, (int)$ticket->fields['global_validation']);

        // accept first validation, second one is still WAITING - test on $tickets_id_2
        // one validation is accepted, the other is waiting -> global_validation status should be WAITING
        $this->login('glpi', 'glpi');
        $validation = new TicketValidation();
        $this->assertTrue(
            $validation->getFromDBByCrit([
                'tickets_id' => $tickets_id_2,
                'itemtype_target' => 'User',
                'items_id_target' => $user_glpi->getID(),
            ])
        );

        // update itil validation step to require 50%, so next assertion returns WAITING, and the seconde return ACCEPTED
        // find ticket itil_validationstep -> update it
        $validation = new TicketValidation();
        $validation->getFromDBByCrit([
            'tickets_id' => $tickets_id_2,
            'itemtype_target' => 'User',
            'items_id_target' => $user_glpi->getID(),
        ]);
        $this->updateItem(
            TicketValidationStep::class,
            $validation->fields['itils_validationsteps_id'],
            ['minimal_required_validation_percent' => 50]
        );

        $this->assertTrue(
            $validation->update([
                'id' => $validation->fields['id'],
                'status' => CommonITILValidation::ACCEPTED
            ])
        );

        // reload ticket because global_validation is updated at Validation update
        $this->assertTrue($ticket->getFromDB($tickets_id_2));
        $this->assertValidationStatusEquals(CommonITILValidation::ACCEPTED, (int)$ticket->fields['global_validation']);

        // accept second one, both are accepted -> global_validation status should be ACCEPTED
        $this->login('approval', 'approval');
        $validation = new TicketValidation();
        $this->assertTrue(
            $validation->getFromDBByCrit([
                'tickets_id' => $tickets_id_2,
                'itemtype_target' => 'User',
                'items_id_target' => $user_approval->getID(),
            ])
        );

        $validation->update([
            'id' => $validation->fields['id'],
            'status' => CommonITILValidation::ACCEPTED
        ]);

        $this->assertTrue($ticket->getFromDB($tickets_id_2));
        $this->assertValidationStatusEquals(CommonITILValidation::ACCEPTED, (int)$ticket->fields['global_validation']);
    }

    public function testCreateValidationCreateAnAssociatedITILValidationStepWithDefaultValidationStep(): void
    {
        // arrange : create an itil object
        $validation_classname = $this->getValidationClassname();
        $this->login();
        /** @var CommonITILObject $itil */
        $itil = $this->createItem($this->getITILClassname(),  [
            'name' => __FUNCTION__,
            'content' => __FUNCTION__,
        ]);

        $validationstep = $this->getInitialDefaultValidationStep();

        // act : create validation
        $validation = $this->createItem($validation_classname, [
            $itil::getForeignKeyField() => $itil->getID(),
            'itemtype_target' => 'User',
            'items_id_target' => $_SESSION['glpiID'],
        ]);

        // assert
        // - validation has an associated itil_validationstep
        $this->assertGreaterThan(0, $validation->fields['itils_validationsteps_id']);

        // - the itil_validationstep is created with the default validation step reference and threshold
        $itil_validationstep = new ($this->getITILValidationStepClassname());
        $itil_validationstep->getFromDB($validation->fields['itils_validationsteps_id']);

        $this->assertEquals($validationstep->getID(), $itil_validationstep->fields['validationsteps_id']);
        $this->assertEquals($validationstep->fields['minimal_required_validation_percent'], $itil_validationstep->fields['minimal_required_validation_percent']);
    }

    /**
     * Same test as above but with a different validation step
     */
    public function testCreateValidationCreateAnAssociatedITILValidationStepWithNewValidationStep(): void
    {
        // arrange : create an itil object
        $this->login();
        $validation_classname = $this->getValidationClassname();

        /** @var CommonITILObject $itil */
        $itil = $this->createItem($this->getITILClassname(),  [
            'name' => __FUNCTION__,
            'content' => __FUNCTION__,
        ]);

        $validationstep = $this->createValidationStep(77);

        // act : create validation
        $validation = $this->createItem($validation_classname, [
            $itil::getForeignKeyField() => $itil->getID(),
            'itemtype_target' => 'User',
            'items_id_target' => $_SESSION['glpiID'],
            '_validationsteps_id' => $validationstep->getID(),
        ]);

        // assert
        // - validation has an associated itil_validationstep
        $this->assertGreaterThan(0, $validation->fields['itils_validationsteps_id']);

        // - the itil_validationstep is created with the default validation step reference and threshold
        $itil_validationstep = new ($this->getITILValidationStepClassname());
        $itil_validationstep->getFromDB($validation->fields['itils_validationsteps_id']);

        $this->assertEquals($validationstep->getID(), $itil_validationstep->fields['validationsteps_id'], 'Validationstep not correctly set using _validationsteps_id');
        $this->assertEquals($validationstep->fields['minimal_required_validation_percent'], $itil_validationstep->fields['minimal_required_validation_percent']);
    }

    /**
     * Same test as above but with a different threshold
     */
    public function testCreateValidationCreateAnAssociatedITILValidationStepWithSpecifiedThreshold(): void
    {
        // arrange : create an itil object
        $this->login();
        $threshold = 88;
        $validation_classname = $this->getValidationClassname();

        /** @var CommonITILObject $itil */
        $itil = $this->createItem($this->getITILClassname(),  [
            'name' => __FUNCTION__,
            'content' => __FUNCTION__,
        ]);
        $validationstep = $this->getInitialDefaultValidationStep();
        assert($validationstep->fields['minimal_required_validation_percent'] !== $threshold, 'can not test with the same threshold');

        // act : create validation
        $validation = $this->createItem($validation_classname, [
            $itil::getForeignKeyField() => $itil->getID(),
            'itemtype_target' => 'User',
            'items_id_target' => $_SESSION['glpiID'],
            '_validationsteps_threshold' => $threshold,
        ]);

        // assert
        // - validation has an associated itil_validationstep
        $this->assertGreaterThan(0, $validation->fields['itils_validationsteps_id']);

        // - the itil_validationstep is created with the default validation step reference and threshold
        $itil_validationstep = new ($this->getITILValidationStepClassname());
        $itil_validationstep->getFromDB($validation->fields['itils_validationsteps_id']);

        $this->assertEquals($validationstep->getID(), $itil_validationstep->fields['validationsteps_id'], 'Validationstep not correctly set using _validationsteps_id');
        $this->assertEquals($threshold, $itil_validationstep->fields['minimal_required_validation_percent']);
    }

    /**
     * validationstep and threshold are changed
     */
    public function testUpdateValidationUpdateAnAssociatedITILValidationStep(): void
    {
        // arrange : create an itil object
        $this->login();
        $threshold = 88;
        $validation_classname = $this->getValidationClassname();

        /** @var CommonITILObject $itil */
        $itil = $this->createItem($this->getITILClassname(),  [
            'name' => __FUNCTION__,
            'content' => __FUNCTION__,
        ]);
        $validationstep = $this->getInitialDefaultValidationStep();
        assert($validationstep->fields['minimal_required_validation_percent'] !== $threshold, 'can not test with the same threshold');

        // act + check: create validation (tested aboved)
        $validation = $this->createItem($validation_classname, [
            $itil::getForeignKeyField() => $itil->getID(),
            'itemtype_target' => 'User',
            'items_id_target' => $_SESSION['glpiID'],
//            '_validationsteps_threshold' => $threshold,
        ]);

        $this->assertGreaterThan(0, $validation->fields['itils_validationsteps_id']);

        // - the itil_validationstep is created with the default validation step reference and threshold
        $itil_validationstep = new ($this->getITILValidationStepClassname());
        $itil_validationstep->getFromDB($validation->fields['itils_validationsteps_id']);

        $this->assertEquals($validationstep->getID(), $itil_validationstep->fields['validationsteps_id'], 'Validationstep not correctly set using _validationsteps_id');
        $this->assertEquals($validationstep->fields['minimal_required_validation_percent'], $itil_validationstep->fields['minimal_required_validation_percent']);

        // act 2 : update validation step & threshold
        $new_validationstep = $this->createValidationStep(100);
        assert($new_validationstep->fields['minimal_required_validation_percent'] !== $threshold, 'can not test with the same threshold');

        $validation = $this->updateItem(
            $validation::class,
            $validation->getID(),
            [
                '_validationsteps_threshold' => $threshold,
                '_validationsteps_id' => $new_validationstep->getID(),

            ]);

        // assert
        $itil_validationstep->getFromDB($validation->fields['itils_validationsteps_id']);

        $this->assertEquals($new_validationstep->getID(), $itil_validationstep->fields['validationsteps_id'], 'Validationstep not correctly set using _validationsteps_id');
        $this->assertEquals($threshold, $itil_validationstep->fields['minimal_required_validation_percent']);
    }

    public function testGlobalValidationUpdate(): void
    {
        $this->login('glpi', 'glpi');
        $uid1 = getItemByTypeName('User', 'glpi', true);

        // --- single ACCEPTED validation & 100% required -> \ChangeValidation|TicketValidation::computeValidationStatus($itil) returns ACCEPTED
        $itil = $this->createItem($this->getITILClassname(), [
            'name' => 'Global_Validation_Update',
            'content' => 'Global_Validation_Update',
        ]);

        $validation_1 = $this->createItem($this->getValidationClassname(), [
            $this->getITILClassname()::getForeignKeyField() => $itil->getID(), // change_id, ticket_id
            'itemtype_target'   => User::class,
            'items_id_target'   => $uid1,
        ]);
        $this->updateITIL_ValidationStepOfItil($validation_1, 100); // 100% required is default, added to be explicit

        $this->updateItem($this->getValidationClassname(), $validation_1->getID(), [
            'status'  => CommonITILValidation::ACCEPTED,
        ]);

        // --- 0% required -> \ChangeValidation|TicketValidation::computeValidationStatus($itil) returns ACCEPTED
        $this->updateITIL_ValidationStepOfItil($validation_1, 0);
        $this->assertValidationStatusEquals(CommonITILValidation::ACCEPTED, $this->getValidationClassname()::computeValidationStatus($itil));

        // ---- add a second WAITING validation & 50% required -> \ChangeValidation|TicketValidation::computeValidationStatus($itil) returns WAITING
        // 1 ACCEPTED validation + 1 WAITING validation
        $this->updateITIL_ValidationStepOfItil($validation_1, 50);

        $validation_2 = $this->createItem($this->getValidationClassname(), [
            $this->getITILClassname()::getForeignKeyField()        => $itil->getID(),
            'itemtype_target'   => User::class,
            'items_id_target'   => $uid1,
        ]);
        $this->updateItem($this->getValidationClassname(), $validation_2->getID(), [
            'status'  => CommonITILValidation::WAITING,
        ]);

        $this->assertValidationStatusEquals(CommonITILValidation::ACCEPTED, $this->getValidationClassname()::computeValidationStatus($itil));

        // ---- 100% required -> \ChangeValidation::computeValidationStatus($itil) returns WAITING
        // unchanged : 1 ACCEPTED validation + 1 WAITING validation
        $this->updateITIL_ValidationStepOfItil($validation_1, 100);
        $this->assertValidationStatusEquals(CommonITILValidation::WAITING, $this->getValidationClassname()::computeValidationStatus($itil));

        // --- add a third validation & update itils_validationstep to 100% required -> \ChangeValidation::computeValidationStatus($itil) returns WAITING
        // 1 ACCEPTED validation + 1 WAITING validation + 1 REFUSED validation
        $this->updateITIL_ValidationStepOfItil($validation_1, 0);

        $v3_id = $this->createItem($this->getValidationClassname(), [
            $this->getITILClassname()::getForeignKeyField()        => $itil->getID(),
            'itemtype_target'   => User::class,
            'items_id_target'   => $uid1,
        ]);

        $this->updateItem($this->getValidationClassname(), $v3_id->getID(), [
            'status'  => CommonITILValidation::REFUSED,
            'comment_validation' => 'I refuse to validate.',
        ]);

        $this->assertValidationStatusEquals(CommonITILValidation::ACCEPTED, $this->getValidationClassname()::computeValidationStatus($itil));

        // ---- 100% required -> \ChangeValidation::computeValidationStatus($itil) returns REFUSED
        // 1 ACCEPTED validation + 1 WAITING validation + 1 REFUSED validation (unchanged)
        $this->updateITIL_ValidationStepOfItil($validation_1, 100);
        $this->assertValidationStatusEquals(CommonITILValidation::REFUSED, $this->getValidationClassname()::computeValidationStatus($itil));

        // ---- 50% required -> \ChangeValidation::computeValidationStatus($itil) returns WAITING
        // 1 ACCEPTED validation + 1 WAITING validation + 1 REFUSED validation (unchanged)
        $this->updateITIL_ValidationStepOfItil($validation_1, 50);
        $this->assertValidationStatusEquals(CommonITILValidation::WAITING, $this->getValidationClassname()::computeValidationStatus($itil));

        // ---- 33% required -> \ChangeValidation::computeValidationStatus($itil) returns WAITING
        // 1 ACCEPTED validation + 1 WAITING validation + 1 REFUSED validation (unchanged)
        $this->updateITIL_ValidationStepOfItil($validation_1, 33);
        $this->assertValidationStatusEquals(CommonITILValidation::ACCEPTED, $this->getValidationClassname()::computeValidationStatus($itil));
    }

    /**
     * Status computation is done on testComputeXXXTests()
     * Here, test that ticket global_validation is updated when a validation status is updated
     */
    public function testTicketValidationStatusUpdated()
    {
        $this->login();
        // add a validation in same step
        $vs = $this->createValidationStep(50);
        [$itil, $ivs] = $this->createITILSValidationStepWithValidations($vs, [CommonITILValidation::WAITING]);
        // assert validation is created with the expected status
        $this->assertValidationStatusEquals(CommonITILValidation::WAITING, (int)$itil->fields['global_validation']);
        $this->addITILValidationStepWithValidations($vs, [CommonITILValidation::ACCEPTED], $itil);
        assert(true === $itil->getFromDB($itil->getID()));
        $this->assertValidationStatusEquals(CommonITILValidation::ACCEPTED, $itil->fields['global_validation']);

        // add a validation in a new step (same code as above but with a new validation step)
        $vs = $this->createValidationStep(0);
        [$itil, $ivs] = $this->createITILSValidationStepWithValidations($vs, [CommonITILValidation::WAITING]);
        // assert validation is created with the expected status
        $this->assertValidationStatusEquals(CommonITILValidation::WAITING, (int)$itil->fields['global_validation']);
        $vs2 = $this->createValidationStep(0);
        $this->addITILValidationStepWithValidations($vs2, [CommonITILValidation::REFUSED], $itil);
        assert(true === $itil->getFromDB($itil->getID()));
        $this->assertValidationStatusEquals(CommonITILValidation::REFUSED, $itil->fields['global_validation']);

        // remove a validation (same as above but with a validation removed)
        $vs = $this->createValidationStep(0);
        [$itil, $ivs] = $this->createITILSValidationStepWithValidations($vs, [CommonITILValidation::WAITING]);
        // assert validation is created with the expected status
        $this->assertValidationStatusEquals(CommonITILValidation::WAITING, (int)$itil->fields['global_validation']);
        $vs2 = $this->createValidationStep(0);
        $ivs = $this->addITILValidationStepWithValidations($vs2, [CommonITILValidation::REFUSED], $itil);
        assert(true === $itil->getFromDB($itil->getID()));
        $this->assertValidationStatusEquals(CommonITILValidation::REFUSED, $itil->fields['global_validation']);
        $validation = $itil::getValidationClassInstance();
        assert(true === $validation->getFromDBByCrit([$itil::getForeignKeyField() => $itil->getID(), 'itils_validationsteps_id' => $ivs->getID()])); // find validation
        assert(true === $validation->delete(['id' => $validation->getID()])); // delete validation
        assert(true === $itil->getFromDB($itil->getID())); // reload itil
        $this->assertValidationStatusEquals(CommonITILValidation::WAITING, $itil->fields['global_validation']);

        // update a validation
        $vs = $this->createValidationStep(100);
        [$itil, $ivs] = $this->createITILSValidationStepWithValidations($vs, [CommonITILValidation::WAITING]);
        $this->assertValidationStatusEquals(CommonITILValidation::WAITING, (int)$itil->fields['global_validation']);
        $validation = $itil::getValidationClassInstance();
        assert(true === $validation->getFromDBByCrit([$itil::getForeignKeyField() => $itil->getID()]));
        assert(true === $validation->update(['id' => $validation->getID(), 'status' => CommonITILValidation::ACCEPTED]));
        assert(true === $itil->getFromDB($itil->getID()));
        assert(CommonITILValidation::ACCEPTED === $itil->fields['global_validation']);

        // update a validation step required percent
        $vs = $this->createValidationStep(100);
        [$itil, $ivs] = $this->createITILSValidationStepWithValidations($vs, [CommonITILValidation::WAITING, CommonITILValidation::ACCEPTED]);
        $this->assertValidationStatusEquals(CommonITILValidation::WAITING, (int)$itil->fields['global_validation']);
        assert(CommonITILValidation::WAITING === $itil->fields['global_validation']);
        // update itils_validationstep to require 100%
        $ivs->update(['id' => $ivs->getID(), 'minimal_required_validation_percent' => 50]);
        $itil->getFromDB($itil->getID());
        $this->assertValidationStatusEquals(CommonITILValidation::ACCEPTED, $itil->fields['global_validation']);
    }

    public static function testgetNumberToValidateProvider(): array
    {
        return [
            [
                'input'     => [
                    'name'      => 'Closed_With_Validation_Request',
                    'content'   => 'Closed_With_Validation_Request',
                ],
                'expected'  => true,
                'user_id'   => getItemByTypeName('User', 'glpi', true)
            ],
            [
                'input'     => [
                    'name' => 'With_Validation_Request',
                    'content' => 'With_Validation_Request',
                    'status' =>  CommonITILObject::SOLVED
                ],
                'expected'  => false,
                'user_id'   => getItemByTypeName('User', 'glpi', true)
            ],
            [
                'input'     => [
                    'name' => 'With_Validation_Request',
                    'content' => 'With_Validation_Request',
                    'status' =>  CommonITILObject::CLOSED
                ],
                'expected'  => false,
                'user_id'   => getItemByTypeName('User', 'glpi', true)
            ],
        ];
    }

    #[DataProvider('testgetNumberToValidateProvider')]
    public function testgetNumberToValidate(
        array $input,
        bool $expected,
        int $user_id
    ): void {
        $this->login();

        $initial_count = $this->getValidationClassname()::getNumberToValidate($user_id);

        /** Create a itil, approval requested */
        $itil = $this->createItem($this->getITILClassname(), $input);

        $this->createItem($this->getValidationClassname(), [
            $itil::getForeignKeyField()      => $itil->getID(),
            'itemtype_target' => 'User',
            'items_id_target' => $user_id,
            '_validationsteps_id' => $this->getInitialDefaultValidationStep()->getID()
        ]);

        $this->assertEquals($expected ? ($initial_count + 1) : $initial_count, $this->getValidationClassname()::getNumberToValidate($user_id));
    }

    // @todoseb quel interet de ce test, déjà tester dans testCreateValidationCreateAnAssociatedITILValidationStep3
//    public function testThresholdIsSetWhenCreatingValidation(): void
//    {
//        // arrange - create a validation step + an itil
//        $this->login();
//        $vs_100 = $this->createValidationStep(100);
//
//        /** @var CommonITILObject $itil */
//        $itil = $this->createItem($this->getITILClassname(),  [
//            'name' => __FUNCTION__,
//            'content' => __FUNCTION__,
////            '_validationstep_id' => $vs_100->getID(), // @todoseb pas ajouter ici, on le testera indirectement par la Rule, sans le spécifier, ce'st la rule qui le fera
////            '_validationsteps_threshold' => 50,
//        ]);
//
//        // act : create a validation for the itil
//        $validation = $this->createItem($this->getValidationClassname(), [
//            $itil::getForeignKeyField() => $itil->getID(),
//            'itemtype_target' => User::class,
//            'items_id_target' => getItemByTypeName('User', 'glpi', true),
//            '_validationsteps_id' => $vs_100->getID(),
//            '_validationsteps_threshold' => 50,
//        ]);
//
//        // assert : itil_validationstep is created as expected : association with the validation step + threshold
//        $itil_validationstep_id = $validation->fields['itils_validationsteps_id'];
//        $itil_validationstep = $itil::getValidationStepInstance();
//        $itil_validationstep->getFromDB($itil_validationstep_id);
//
//        $this->assertEquals($itil_validationstep->fields['validationsteps_id'], $vs_100->getID());
//        $this->assertEquals($itil_validationstep->fields['minimal_required_validation_percent'], $vs_100->fields['minimal_required_validation_percent']);
//    }

    public function testcomputeValidationStatusReturnNone(): void
    {
        $itil = $this->createItem($this->getITILClassname(), ['name' => 'ITIL 1', 'content' => 'ITIL 1']);
        $this->assertEquals(CommonITILValidation::NONE, $this->getValidationClassname()::computeValidationStatus($itil));
    }

    /**
     * One validation is REFUSED : the ticket global_validation is REFUSED
     */
    public function testComputeValidationStatusReturnRefused(): void
    {
        $this->login();
        // itil with one refused itil validation step
        $vs50 = $this->createValidationStep(50);
        [$itil, $itil_vs] = $this->createITILSValidationStepWithValidations($vs50, [CommonITILValidation::REFUSED]);
        // check created itil_validation step status is REFUSED before testing
        $this->assertValidationStatusEquals(CommonITILValidation::REFUSED, $this->getValidationClassname()::computeValidationStatus($itil));

        // + an accepted itil validation step (use previous itil)
        $vs2 = $this->createValidationStep(50);
        $itil_vs = $this->addITILValidationStepWithValidations($vs2, [CommonITILValidation::ACCEPTED], $itil);
        $this->assertValidationStatusEquals(CommonITILValidation::REFUSED, $this->getValidationClassname()::computeValidationStatus($itil));

        // itil with a waiting + an accepted + refused validation step
        [$itil, $itil_vs] = $this->createITILSValidationStepWithValidations($vs50, [CommonITILValidation::WAITING]);

        $vs100 = $this->createValidationStep(100);
        $itil_vs = $this->addITILValidationStepWithValidations($vs100, [CommonITILValidation::REFUSED], $itil);

        $vs100_2 = $this->createValidationStep(100);
        $itil_vs = $this->addITILValidationStepWithValidations($vs100_2, [CommonITILValidation::ACCEPTED], $itil);

        $this->assertValidationStatusEquals(CommonITILValidation::REFUSED, $this->getValidationClassname()::computeValidationStatus($itil));
    }

    /**
     * One validation is WAITING : the ticket global_validation is WAITING
     */
    public function testComputeValidationStatusReturnWaiting(): void
    {
        $this->login();
        // ticket with one waiting itil validation step
        $vs50 = $this->createValidationStep(50);
        [$itil, $itil_vs] = $this->createITILSValidationStepWithValidations($vs50, [CommonITILValidation::WAITING]);
        $this->assertValidationStatusEquals(CommonITILValidation::WAITING, $this->getValidationClassname()::computeValidationStatus($itil));

        // + an accepted itil validation step (use previous ticket)
        $vs100 = $this->createValidationStep(100);
        $itil_vs = $this->addITILValidationStepWithValidations($vs100, [CommonITILValidation::ACCEPTED], $itil);
        $this->assertValidationStatusEquals(CommonITILValidation::WAITING, $this->getValidationClassname()::computeValidationStatus($itil));

        // second test
        // itil with an accepted + waiting itil validation step
        [$itil, $itil_vs] = $this->createITILSValidationStepWithValidations($vs50, [CommonITILValidation::ACCEPTED]);
        $itil_vs = $this->addITILValidationStepWithValidations($vs100, [CommonITILValidation::WAITING], $itil);

        $this->assertValidationStatusEquals(CommonITILValidation::WAITING, $this->getValidationClassname()::computeValidationStatus($itil));
    }

    /**
     * - create a ticket with a validated state.
     * - update a validation step
     * - check ticket validation status has changed
     */
    public function testIITLValidationStatusChangesWhenITILValidationStepPercentageIsChanged(): void
    {
        $this->login();
        // arrange
        $vs50 = $this->createValidationStep(50);
        [$itil, $itil_vs] = $this->createITILSValidationStepWithValidations($vs50, [CommonITILValidation::ACCEPTED, CommonITILValidation::REFUSED], CommonITILValidation::ACCEPTED);
        $this->assertValidationStatusEquals(CommonITILValidation::ACCEPTED, $itil->fields['global_validation']);

        // act - update itil validation step
        $this->updateItem($itil_vs::class, $itil_vs->getID(), ['minimal_required_validation_percent' => 100]);

        // assert
        $itil->getFromDB($itil->getID());
        $this->assertValidationStatusEquals(CommonITILValidation::REFUSED, $itil->fields['global_validation']);
    }

    /**
     * All validations are ACCEPTED : the ticket global_validation is ACCEPTED
     */
    public function testComputeValidationStatusReturnAccepted(): void
    {
        $this->login();
        // itil with one ACCEPTED itil validation step
        $vs50 = $this->createValidationStep(50);
        [$itil, $itil_vs] = $this->createITILSValidationStepWithValidations($vs50, [CommonITILValidation::ACCEPTED]);
        $this->assertValidationStatusEquals(CommonITILValidation::ACCEPTED, $this->getValidationClassname()::computeValidationStatus($itil));

        // many validation step  (use previous itil)
        $vs100 = $this->createValidationStep(100);
        $itil_vs = $this->addITILValidationStepWithValidations($vs100, [CommonITILValidation::ACCEPTED], $itil);
        $this->assertValidationStatusEquals(CommonITILValidation::ACCEPTED, $this->getValidationClassname()::computeValidationStatus($itil));

        // + another one
        $vs100_2 = $this->createValidationStep(100);
        $itil_vs = $this->addITILValidationStepWithValidations($vs100_2, [CommonITILValidation::ACCEPTED], $itil);
        $this->assertValidationStatusEquals(CommonITILValidation::ACCEPTED, $this->getValidationClassname()::computeValidationStatus($itil));

        // + another one
        $vs100_3 = $this->createValidationStep(100);
        $itil_vs = $this->addITILValidationStepWithValidations($vs100_3, [CommonITILValidation::ACCEPTED], $itil);
        $this->assertValidationStatusEquals(CommonITILValidation::ACCEPTED, $this->getValidationClassname()::computeValidationStatus($itil));

        // itil with a refused + an accepted validation step, then remove the refused validation
        [$itil, $itil_vs] = $this->createITILSValidationStepWithValidations($vs50, [CommonITILValidation::REFUSED]);
        $tvs = $this->addITILValidationStepWithValidations($vs100, [CommonITILValidation::ACCEPTED], $itil);
        // find and delete the refused validation
        $tv = new ($this->getValidationClassname());
        $tv->getFromDBByCrit([ $this->getITILClassname()::getForeignKeyField() => $itil->getID(), 'itils_validationsteps_id' => $itil_vs->getID()]);
        assert($tv->delete(['id' => $tv->getID()]));
        $this->assertValidationStatusEquals(CommonITILValidation::ACCEPTED, $this->getValidationClassname()::computeValidationStatus($itil));
    }

    /**
     * Add a validation to the given ITIL object
     */
    private function addValidation(CommonITILObject $itil, ?int $validationstep_id = null, ?int $threshold = null): CommonITILValidation
    {
        $validation_classname = $this->getValidationClassname();
        $validationstep_id ??= $this->getInitialDefaultValidationStep()->getID();
        $threshold ??= $this->getInitialDefaultValidationStep()->fields['minimal_required_validation_percent'];

        if (!isset($_SESSION['glpiID'])) {
            throw new RuntimeException('$_SESSION["glpiID"] is not set, did you forget to call $this->login() ?');
        }

        // create validation
        // @todoseb faire une test qui reproduit ça
        return $this->createItem($validation_classname, [
            $itil::getForeignKeyField() => $itil->getID(),
//            'itils_validationsteps_id' => $itils_validationsteps->getID(),
            'itemtype_target' => 'User',
            'items_id_target' => $_SESSION['glpiID'],
            '_validationstep_id' => $validationstep_id,
            '_validationsteps_threshold' => $threshold,
        ]);
    }
}
