<?php

namespace PhpRQ;

/**
 * @author Jakub Chábek <jakub.chabek@heureka.cz>
 */
class UniqueQueueSyncTest extends BaseTestCase
{

    public function testAddItem()
    {
        $queue = new UniqueQueue($this->redis, 'test', [
            Base::OPT_SLAVES_SYNC_ENABLED        => true,
            Base::OPT_SLAVES_SYNC_REQUIRED_COUNT => 5,
        ]);
        try {
            $queue->addItem(1);
        } catch (\PhpRQ\Exception\NotEnoughSlavesSynced $e) {}

        $this->assertSame(['1'], $this->redis->lrange('test', 0, 5));
        $this->assertSame(['1'], $this->redis->smembers('test-unique'));
        $this->assertKeys(['test', 'test-unique']);
    }

    public function testAddItems()
    {
        $queue = new UniqueQueue($this->redis, 'test', [
            Base::OPT_SLAVES_SYNC_ENABLED        => true,
            Base::OPT_SLAVES_SYNC_REQUIRED_COUNT => 5,
        ]);
        try {
            $queue->addItems([1, 3, 5, 3]);
        } catch (\PhpRQ\Exception\NotEnoughSlavesSynced $e) {}

        $this->assertSame(['5', '3', '1'], $this->redis->lrange('test', 0, 5));

        $items = $this->redis->smembers('test-unique');
        $this->assertCount(3, $items);
        $this->assertTrue(in_array('1', $items, true));
        $this->assertTrue(in_array('3', $items, true));
        $this->assertTrue(in_array('5', $items, true));
        $this->assertKeys(['test', 'test-unique']);
    }

    public function testAckItem()
    {
        $queue = new UniqueQueue($this->redis, 'test', [
            Base::OPT_SLAVES_SYNC_ENABLED        => true,
            Base::OPT_SLAVES_SYNC_REQUIRED_COUNT => 5,
        ], $this->getTimeMock());

        $processingQueue = sprintf('test-processing-%s[%d][%d]', gethostname(), getmypid(), self::TIME_MOCK);
        $this->redis->lpush($processingQueue, [1, 5, 3]);
        $this->redis->hset('test-timeouts', $processingQueue, self::MICRO_TIME_MOCK);

        try {
            $queue->ackItem(1);
        } catch (\PhpRQ\Exception\NotEnoughSlavesSynced $e) {}

        $this->assertSame(['3', '5'], $this->redis->lrange($processingQueue, 0, 5));
        $this->assertSame([$processingQueue => (string)self::MICRO_TIME_MOCK], $this->redis->hgetall('test-timeouts'));
        $this->assertKeys([
            $processingQueue,
            'test-timeouts'
        ]);
    }

    public function testAckItems()
    {
        $queue = new UniqueQueue($this->redis, 'test', [
            Base::OPT_SLAVES_SYNC_ENABLED        => true,
            Base::OPT_SLAVES_SYNC_REQUIRED_COUNT => 5,
        ], $this->getTimeMock());

        $processingQueue = sprintf('test-processing-%s[%d][%d]', gethostname(), getmypid(), self::TIME_MOCK);
        $this->redis->lpush($processingQueue, [1, 5, 3, 6, 7]);
        $this->redis->hset('test-timeouts', $processingQueue, self::MICRO_TIME_MOCK);

        try {
            $queue->ackItems([1, 3]);
        } catch (\PhpRQ\Exception\NotEnoughSlavesSynced $e) {}

        $this->assertSame(['7', '6', '5'], $this->redis->lrange($processingQueue, 0, 5));
        $this->assertSame([$processingQueue => (string)self::MICRO_TIME_MOCK], $this->redis->hgetall('test-timeouts'));
        $this->assertKeys([
            $processingQueue,
            'test-timeouts'
        ]);
    }

    public function testRejectItem()
    {
        $queue = new UniqueQueue($this->redis, 'test', [
            Base::OPT_SLAVES_SYNC_ENABLED        => true,
            Base::OPT_SLAVES_SYNC_REQUIRED_COUNT => 5,
        ], $this->getTimeMock());

        $processingQueue = sprintf('test-processing-%s[%d][%d]', gethostname(), getmypid(), self::TIME_MOCK);
        $this->redis->lpush($processingQueue, [1, 5, 3]);
        $this->redis->hset('test-timeouts', $processingQueue, self::MICRO_TIME_MOCK);

        try {
            $queue->rejectItem(1);
        } catch (\PhpRQ\Exception\NotEnoughSlavesSynced $e) {}

        // order of the items is lost when using UniqueQueue:rejectItem, be aware of that
        $this->assertSame(['1'], $this->redis->lrange('test', 0, 5));
        $this->assertSame(['1'], $this->redis->smembers('test-unique'));
        $this->assertSame(['3', '5'], $this->redis->lrange($processingQueue, 0, 5));
        $this->assertSame([$processingQueue => (string)self::MICRO_TIME_MOCK], $this->redis->hgetall('test-timeouts'));
        $this->assertKeys([
            'test',
            'test-unique',
            $processingQueue,
            'test-timeouts'
        ]);
    }

    public function testRejectItems()
    {
        $queue = new UniqueQueue($this->redis, 'test', [
            Base::OPT_SLAVES_SYNC_ENABLED        => true,
            Base::OPT_SLAVES_SYNC_REQUIRED_COUNT => 5,
        ], $this->getTimeMock());

        $processingQueue = sprintf('test-processing-%s[%d][%d]', gethostname(), getmypid(), self::TIME_MOCK);
        $this->redis->lpush($processingQueue, [1, 5, 3, 6, 7]);
        $this->redis->hset('test-timeouts', $processingQueue, self::MICRO_TIME_MOCK);

        try {
            $queue->rejectItems([1, 3]);
        } catch (\PhpRQ\Exception\NotEnoughSlavesSynced $e) {}

        // order of the items is preserved with UniqueQueue:rejectItems only if there is a reject on all the remaining
        // items at once. Consecutive calls of this method causes the lost of the items order.
        $this->assertSame(['3', '1'], $this->redis->lrange('test', 0, 5));
        $items = $this->redis->smembers('test-unique');
        $this->assertCount(2, $items);
        $this->assertTrue(in_array('1', $items, true));
        $this->assertTrue(in_array('3', $items, true));
        $this->assertSame(['7', '6', '5'], $this->redis->lrange($processingQueue, 0, 5));
        $this->assertSame([$processingQueue => (string)self::MICRO_TIME_MOCK], $this->redis->hgetall('test-timeouts'));
        $this->assertKeys([
            'test',
            'test-unique',
            $processingQueue,
            'test-timeouts'
        ]);
    }

    public function testRejectBatch()
    {
        $queue = new UniqueQueue($this->redis, 'test', [
            Base::OPT_SLAVES_SYNC_ENABLED        => true,
            Base::OPT_SLAVES_SYNC_REQUIRED_COUNT => 5,
        ], $this->getTimeMock());

        $processingQueue = sprintf('test-processing-%s[%d][%d]', gethostname(), getmypid(), self::TIME_MOCK);
        $this->redis->lpush($processingQueue, [1, 5, 3, 6, 7]);
        $this->redis->hset('test-timeouts', $processingQueue, self::MICRO_TIME_MOCK);

        try {
            $queue->ackItem(3);
        } catch (\PhpRQ\Exception\NotEnoughSlavesSynced $e) {}
        try {
            $queue->rejectBatch();
        } catch (\PhpRQ\Exception\NotEnoughSlavesSynced $e) {}

        $this->assertSame(['7', '6', '5', '1'], $this->redis->lrange('test', 0, 10));
        $items = $this->redis->smembers('test-unique');
        $this->assertCount(4, $items);
        $this->assertTrue(in_array('1', $items, true));
        $this->assertTrue(in_array('5', $items, true));
        $this->assertTrue(in_array('6', $items, true));
        $this->assertTrue(in_array('7', $items, true));
        $this->assertKeys([
            'test',
            'test-unique',
        ]);
    }

    public function testReEnqueueTimedOutItems()
    {
        $queue = new UniqueQueue($this->redis, 'test', [
            Base::OPT_SLAVES_SYNC_ENABLED        => true,
            Base::OPT_SLAVES_SYNC_REQUIRED_COUNT => 5,
        ], $this->getTimeMock());

        $processingQueue1 = sprintf('test-processing-%s[%d][%d]', gethostname(), getmypid(), self::TIME_MOCK - 15);
        $this->redis->lpush($processingQueue1, [1, 5, 3]);
        $this->redis->hset('test-timeouts', $processingQueue1, self::MICRO_TIME_MOCK - 15);

        $processingQueue2 = sprintf('test-processing-%s[%d][%d]', gethostname(), getmypid(), self::TIME_MOCK - 10);
        $this->redis->lpush($processingQueue2, [1, 4, 6]);
        $this->redis->hset('test-timeouts', $processingQueue2, self::MICRO_TIME_MOCK - 10);

        $processingQueue3 = sprintf('test-processing-%s[%d][%d]', gethostname(), getmypid(), self::TIME_MOCK - 5);
        $this->redis->lpush($processingQueue3, [4, 7, 8]);
        $this->redis->hset('test-timeouts', $processingQueue3, self::MICRO_TIME_MOCK - 5);

        try {
            $queue->reEnqueueTimedOutItems(7);
        } catch (\PhpRQ\Exception\NotEnoughSlavesSynced $e) {}

        $this->assertSame(['6', '4', '3', '5', '1'], $this->redis->lrange('test', 0, 10));
        $items = $this->redis->smembers('test-unique');
        $this->assertCount(5, $items);
        $this->assertTrue(in_array('1', $items, true));
        $this->assertTrue(in_array('3', $items, true));
        $this->assertTrue(in_array('4', $items, true));
        $this->assertTrue(in_array('5', $items, true));
        $this->assertTrue(in_array('6', $items, true));
        $this->assertSame(['8', '7', '4'], $this->redis->lrange($processingQueue3, 0, 5));
        $this->assertSame(
            [$processingQueue3 => (string)(self::MICRO_TIME_MOCK - 5)],
            $this->redis->hgetall('test-timeouts')
        );
        $this->assertKeys([
            'test',
            'test-unique',
            'test-timeouts',
            $processingQueue3,
        ]);
    }

    public function testReEnqueueAllItems()
    {
        $queue = new UniqueQueue($this->redis, 'test', [
            Base::OPT_SLAVES_SYNC_ENABLED        => true,
            Base::OPT_SLAVES_SYNC_REQUIRED_COUNT => 5,
        ], $this->getTimeMock());

        $processingQueue1 = sprintf('test-processing-%s[%d][%d]', gethostname(), getmypid(), self::TIME_MOCK - 15);
        $this->redis->lpush($processingQueue1, [1, 5, 3]);
        $this->redis->hset('test-timeouts', $processingQueue1, self::MICRO_TIME_MOCK - 15);

        $processingQueue2 = sprintf('test-processing-%s[%d][%d]', gethostname(), getmypid(), self::TIME_MOCK - 10);
        $this->redis->lpush($processingQueue2, [1, 4, 6]);
        $this->redis->hset('test-timeouts', $processingQueue2, self::MICRO_TIME_MOCK - 10);

        $processingQueue3 = sprintf('test-processing-%s[%d][%d]', gethostname(), getmypid(), self::TIME_MOCK - 5);
        $this->redis->lpush($processingQueue3, [4, 7, 8]);
        $this->redis->hset('test-timeouts', $processingQueue3, self::MICRO_TIME_MOCK - 5);

        try {
            $queue->reEnqueueAllItems();
        } catch (\PhpRQ\Exception\NotEnoughSlavesSynced $e) {}

        $this->assertSame(['8', '7', '6', '4', '3', '5', '1'], $this->redis->lrange('test', 0, 10));
        $items = $this->redis->smembers('test-unique');
        $this->assertCount(7, $items);
        $this->assertTrue(in_array('1', $items, true));
        $this->assertTrue(in_array('3', $items, true));
        $this->assertTrue(in_array('4', $items, true));
        $this->assertTrue(in_array('5', $items, true));
        $this->assertTrue(in_array('6', $items, true));
        $this->assertTrue(in_array('7', $items, true));
        $this->assertTrue(in_array('8', $items, true));
        $this->assertKeys([
            'test',
            'test-unique',
        ]);
    }

    public function testDropTimedOutItems()
    {
        $queue = new UniqueQueue($this->redis, 'test', [
            Base::OPT_SLAVES_SYNC_ENABLED        => true,
            Base::OPT_SLAVES_SYNC_REQUIRED_COUNT => 5,
        ], $this->getTimeMock());

        $processingQueue1 = sprintf('test-processing-%s[%d][%d]', gethostname(), getmypid(), self::TIME_MOCK - 15);
        $this->redis->lpush($processingQueue1, [1, 5, 3]);
        $this->redis->hset('test-timeouts', $processingQueue1, self::MICRO_TIME_MOCK - 15);

        $processingQueue2 = sprintf('test-processing-%s[%d][%d]', gethostname(), getmypid(), self::TIME_MOCK - 10);
        $this->redis->lpush($processingQueue2, [1, 4, 6]);
        $this->redis->hset('test-timeouts', $processingQueue2, self::MICRO_TIME_MOCK - 10);

        $processingQueue3 = sprintf('test-processing-%s[%d][%d]', gethostname(), getmypid(), self::TIME_MOCK - 5);
        $this->redis->lpush($processingQueue3, [4, 7, 8]);
        $this->redis->hset('test-timeouts', $processingQueue3, self::MICRO_TIME_MOCK - 5);

        try {
            $queue->dropTimedOutItems(7);
        } catch (\PhpRQ\Exception\NotEnoughSlavesSynced $e) {}

        $this->assertSame([], $this->redis->lrange('test', 0, 5));
        $this->assertSame([], $this->redis->smembers('test-unique'));
        $this->assertSame(['8', '7', '4'], $this->redis->lrange($processingQueue3, 0, 5));
        $this->assertSame(
            [$processingQueue3 => (string)(self::MICRO_TIME_MOCK - 5)],
            $this->redis->hgetall('test-timeouts')
        );
        $this->assertKeys([
            'test-timeouts',
            $processingQueue3,
        ]);
    }

    public function testDropAllItems()
    {
        $queue = new UniqueQueue($this->redis, 'test', [
            Base::OPT_SLAVES_SYNC_ENABLED        => true,
            Base::OPT_SLAVES_SYNC_REQUIRED_COUNT => 5,
        ], $this->getTimeMock());

        $processingQueue1 = sprintf('test-processing-%s[%d][%d]', gethostname(), getmypid(), self::TIME_MOCK - 15);
        $this->redis->lpush($processingQueue1, [1, 5, 3]);
        $this->redis->hset('test-timeouts', $processingQueue1, self::MICRO_TIME_MOCK - 15);

        $processingQueue2 = sprintf('test-processing-%s[%d][%d]', gethostname(), getmypid(), self::TIME_MOCK - 10);
        $this->redis->lpush($processingQueue2, [1, 4, 6]);
        $this->redis->hset('test-timeouts', $processingQueue2, self::MICRO_TIME_MOCK - 10);

        $processingQueue3 = sprintf('test-processing-%s[%d][%d]', gethostname(), getmypid(), self::TIME_MOCK - 5);
        $this->redis->lpush($processingQueue3, [4, 7, 8]);
        $this->redis->hset('test-timeouts', $processingQueue3, self::MICRO_TIME_MOCK - 5);

        try {
            $queue->dropAllItems();
        } catch (\PhpRQ\Exception\NotEnoughSlavesSynced $e) {}

        $this->assertKeys([]);
    }

    public function testClearQueue()
    {
        $queue = new UniqueQueue($this->redis, 'test', [
            UniqueQueue::OPT_DEL_MAX_CHUNK_SIZE  => 2,
            Base::OPT_SLAVES_SYNC_ENABLED        => true,
            Base::OPT_SLAVES_SYNC_REQUIRED_COUNT => 5,

        ], $this->getTimeMock());

        $this->redis->lpush('test', [1, 5, 3]);
        $this->redis->sadd('test-unique', [1, 5, 3]);

        $processingQueue1 = sprintf('test-processing-%s[%d][%d]', gethostname(), getmypid(), self::TIME_MOCK - 15);
        $this->redis->lpush($processingQueue1, [1, 8, 4]);
        $this->redis->hset('test-timeouts', $processingQueue1, self::MICRO_TIME_MOCK - 15);

        $processingQueue2 = sprintf('test-processing-%s[%d][%d]', gethostname(), getmypid(), self::TIME_MOCK - 10);
        $this->redis->lpush($processingQueue2, [2, 6, 7]);
        $this->redis->hset('test-timeouts', $processingQueue2, self::MICRO_TIME_MOCK - 10);

        try {
            $queue->clearQueue();
        } catch (\PhpRQ\Exception\NotEnoughSlavesSynced $e) {}

        $this->assertKeys([]);
    }

}
