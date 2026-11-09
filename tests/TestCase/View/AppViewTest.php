<?php
declare(strict_types=1);

namespace App\Test\TestCase\View;

use App\View\AppView;
use Cake\TestSuite\TestCase;

class AppViewTest extends TestCase
{
    private AppView $view;

    protected function setUp(): void
    {
        parent::setUp();
        $this->view = new AppView();
    }

    public function testGameNumber(): void
    {
        $this->assertSame('0', $this->view->gameNumber(0));
        $this->assertSame('500', $this->view->gameNumber(500));
        $this->assertSame('1K', $this->view->gameNumber(1000));
        $this->assertSame('1,2K', $this->view->gameNumber(1200));
        $this->assertSame('1,23K', $this->view->gameNumber(1234));
        $this->assertSame('12,3K', $this->view->gameNumber(12345));
        $this->assertSame('100K', $this->view->gameNumber(100000));
        $this->assertSame('100M', $this->view->gameNumber(100000000));
        $this->assertSame('1M', $this->view->gameNumber(999900));
        $this->assertSame('1,5B', $this->view->gameNumber(1500000000));
    }
}

