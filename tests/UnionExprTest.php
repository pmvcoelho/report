<?php

namespace atk4\report\tests;


/**
 * Tests basic create, update and delete operatiotns
 */
class UnionExprTest extends \atk4\schema\PHPUnit_SchemaTestCase
{

    private $init_db = 
        [
            'client' => [
                ['name' => 'Vinny'],
                ['name' => 'Zoe'],
            ],
            'invoice' => [
                ['client_id'=>1, 'name'=>'chair purchase', 'amount'=>4],
                ['client_id'=>1, 'name'=>'table purchase', 'amount'=>15],
                ['client_id'=>2, 'name'=>'chair purchase', 'amount'=>4],
            ],
            'payment' => [
                ['client_id'=>1, 'name'=>'prepay', 'amount'=>10],
                ['client_id'=>2, 'name'=>'full pay', 'amount'=>4],
            ],
        ];

    function setUp() {
        parent::setUp();
        $this->t = new Transaction2($this->db);
    }

    function testFieldExpr()
    {
        $this->assertEquals('`amount`', $this->t->expr('[]',[$this->t->getFieldExpr($this->t->m_invoice, 'amount')])->render());
        $this->assertEquals('-`amount`', $this->t->expr('[]',[$this->t->getFieldExpr($this->t->m_invoice, 'amount', '-[]')])->render());
        $this->assertEquals('-NULL', $this->t->expr('[]',[$this->t->getFieldExpr($this->t->m_invoice, 'blah', '-[]')])->render());
    }


    public function testNestedQuery1()
    {
        $t = $this->t;

        $this->assertEquals(
            '((select `name` `name` from `invoice`) UNION ALL (select `name` `name` from `payment`)) `derivedTable`', 
            $t->getSubQuery(['name'])->render()
        );

        $this->assertEquals(
            '((select `name` `name`,-`amount` `amount` from `invoice`) UNION ALL (select `name` `name`,`amount` `amount` from `payment`)) `derivedTable`', 
            $t->getSubQuery(['name', 'amount'])->render()
        );

        $this->assertEquals(
            '((select `name` `name` from `invoice`) UNION ALL (select `name` `name` from `payment`)) `derivedTable`', 
            $t->getSubQuery(['name'])->render()
        );

    }

    /**
     * If field is not set for one of the nested model, instead of generating exception, NULL will be filled in.
     */
    function testMissingField() {
        $t = $this->t;
        $t->m_invoice->addExpression('type','"invoice"');
        $t->addField('type');

        $this->assertEquals(
            '((select ("invoice") `type`,-`amount` `amount` from `invoice`) UNION ALL (select NULL `type`,`amount` `amount` from `payment`)) `derivedTable`', 
            $t->getSubQuery(['type', 'amount'])->render()
        );
    }


    public function testActions()
    {
        $t = $this->t;

        $this->assertEquals(
            'select `name`,`amount` from ((select `name` `name`,-`amount` `amount` from `invoice`) UNION ALL (select `name` `name`,`amount` `amount` from `payment`)) `derivedTable`',
            $t->action('select')->render()
        );

        $this->assertEquals(
            'select `name` from ((select `name` `name` from `invoice`) UNION ALL (select `name` `name` from `payment`)) `derivedTable`',
            $t->action('field', ['name'])->render()
        );

        $this->assertEquals(
            'select sum(`cnt`) from ((select count(*) `cnt` from `invoice`) UNION ALL (select count(*) `cnt` from `payment`)) `derivedTable`',
            $t->action('count')->render()
        );

        $this->assertEquals(
            'select sum(`val`) from ((select sum(-`amount`) `val` from `invoice`) UNION ALL (select sum(`amount`) `val` from `payment`)) `derivedTable`',
            $t->action('fx', ['sum', 'amount'])->render()
        );
    }

    public function testActions2()
    {
        $t = $this->t;
        $this->assertEquals(5, $t->action('count')->getOne());

        $this->assertEquals(-9, $t->action('fx', ['sum', 'amount'])->getOne());
    }

    public function testSubAction1()
    {
        $t = $this->t;
        $this->assertEquals(
            '((select sum(-`amount`) from `invoice`) UNION ALL (select sum(`amount`) from `payment`)) `derivedTable`',
            $t->getSubAction('fx', ['sum','amount'])->render()
        );
    }


    public function testBasics()
    {

        $this->setDB($this->init_db);

        $client = new Client($this->db);

        // There are total of 2 clients
        $this->assertEquals(2, $client->action('count')->getOne());

        // Client with ID=1 has invoices for 19
        $client->load(1);
        $this->assertEquals(19, $client->ref('Invoice')->action('fx', ['sum', 'amount'])->getOne());

        $t = new Transaction2($this->db);
        $this->assertEquals([
            ['name' =>"chair purchase", 'amount' => -4],
            ['name' =>"table purchase", 'amount' => -15],
            ['name' =>"chair purchase", 'amount' => -4],
            ['name' =>"prepay", 'amount' => 10],
            ['name' =>"full pay", 'amount' => 4],
        ], $t->export());


        // Transaction is Union Model
        $client->hasMany('Transaction', new Transaction2());

        $this->assertEquals([
            ['name' =>"chair purchase", 'amount' => -4],
            ['name' =>"table purchase", 'amount' => -15],
            ['name' =>"prepay", 'amount' => 10],
        ], $client->ref('Transaction')->export());
    }

    function testGrouping1() {

        $t = $this->t;

        $t->groupBy('name', ['amount'=>'sum([])']);

        $this->assertEquals(
            '((select `name` `name`,sum(-`amount`) `amount` from `invoice` group by `name`) UNION ALL (select `name` `name`,sum(`amount`) `amount` from `payment` group by `name`)) `derivedTable`', 
            $t->getSubQuery(['name', 'amount'])->render()
        );
    }

    function testGrouping2() {

        $t = $this->t;

        $t->groupBy('name', ['amount'=>'sum([])']);

        $this->assertEquals(
            'select `name`,sum(`amount`) `amount` from ((select `name` `name`,sum(-`amount`) `amount` from `invoice` group by `name`) UNION ALL (select `name` `name`,sum(`amount`) `amount` from `payment` group by `name`)) `derivedTable` group by `name`', 
            $t->action('select', [['name','amount']])->render()
        );
    }

    /**
     * If all nested models have a physical field to which a grouped column can be mapped into, then we should group all our
     * sub-queries
     */
    function testGrouping3()
    {
        $t = $this->t;
        $t->groupBy('name', ['amount'=>'sum([])']);
        $t->setOrder('name');


        $this->assertEquals([
            ['name' =>"chair purchase", 'amount' => -8],
            ['name' =>"full pay", 'amount' => 4],
            ['name' =>"prepay", 'amount' => 10],
            ['name' =>"table purchase", 'amount' => -15],
        ], $t->export());
    }

    /**
     * If a nested model has a field defined through expression, it should be still used in grouping. We should test this
     * with both expressions based off the fields and static expressions (such as "blah")
     */
    function testSubGroupingByExpressions()
    {
        $t = $this->t;
        $t->m_invoice->addExpression('type','"invoice"');
        $t->m_payment->addExpression('type','"payment"');
        $t->addField('type');

        $t->groupBy('type', ['amount'=>'sum([])']);

        $this->assertEquals([
            ['type'=>'invoice', 'amount' => -23],
            ['type' =>'payment', 'amount' => 14],
        ],$t->export(['type','amount']));
    }

    function testReference()
    {
        $c = new Client($this->db);
        $c->hasMany('tr', new Transaction2());

        $this->assertEquals(19, $c->load(1)->ref('Invoice')->action('fx', ['sum', 'amount'])->getOne());
        $this->assertEquals(10, $c->load(1)->ref('Payment')->action('fx', ['sum', 'amount'])->getOne());

        $this->assertEquals(-9, $c->load(1)->ref('tr')->action('fx', ['sum', 'amount'])->getOne());

        $this->assertEquals(
            'select sum(`val`) from ((select sum(-`amount`) `val` from `invoice` where `client_id` = :a) '.
            'UNION ALL (select sum(`amount`) `val` from `payment` where `client_id` = :b)) `derivedTable`',
            $c->load(1)->ref('tr')->action('fx', ['sum', 'amount'])->render()
        );

    }

    /**
     * Aggregation is supposed to work in theory, but MySQL uses "semi-joins" for this type of query which does not support UNION,
     * and therefore it complains about `client`.`id` field.
     *
     * See also: http://stackoverflow.com/questions/8326815/mysql-field-from-union-subselect#comment10267696_8326815 
     */
    function testFieldAggregate()
    {
        $c = new Client($this->db);
        $c->hasMany('tr', new Transaction2())
            ->addField('balance', ['field'=>'amount', 'aggregate'=>'sum']);

        
        /*
        select `client`.`id`,`client`.`name`,(select sum(`val`) from ((select sum(`amount`) `val` from `invoice` where `client_id` = `client`.`id`) UNION ALL (select sum(`amount`) `val` from `payment` where `client_id` = `client`.`id`)) `derivedTable`) `balance` from `client` where `client`.`id` = 1 limit 0, 1

         */
        //$c->load(1);
    }


    /**
     * Model's conditions can still be placed on the original field values.
     */
    function testConditionOnMappedField()
    {
        $t = new Transaction2($this->db);
        $t->m_invoice->addCondition('amount', 4);

        $this->assertEquals([
            ['name' =>"chair purchase", 'amount' => -4],
            ['name' =>"chair purchase", 'amount' => -4],
            ['name' =>"prepay", 'amount' => 10],
            ['name' =>"full pay", 'amount' => 4],
        ], $t->export());
    }
}
