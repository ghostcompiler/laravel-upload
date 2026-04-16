<?php

namespace GhostCompiler\UploadsManager\Tests\Unit;

use GhostCompiler\UploadsManager\Services\UploadManager;
use GhostCompiler\UploadsManager\Tests\TestCase;

class UploadManagerResizeTest extends TestCase
{
    public function test_it_calculates_height_from_width_while_preserving_aspect_ratio(): void
    {
        config()->set('uploads-manager.image_optimization.max_width', 1600);
        config()->set('uploads-manager.image_optimization.max_height', null);

        [$width, $height] = $this->manager()->exposedTargetImageDimensions(4000, 2000);

        $this->assertSame(1600, $width);
        $this->assertSame(800, $height);
    }

    public function test_it_calculates_width_from_height_while_preserving_aspect_ratio(): void
    {
        config()->set('uploads-manager.image_optimization.max_width', null);
        config()->set('uploads-manager.image_optimization.max_height', 900);

        [$width, $height] = $this->manager()->exposedTargetImageDimensions(2000, 4000);

        $this->assertSame(450, $width);
        $this->assertSame(900, $height);
    }

    public function test_it_does_not_upscale_images_when_resize_limits_are_larger_than_original(): void
    {
        config()->set('uploads-manager.image_optimization.max_width', 1600);
        config()->set('uploads-manager.image_optimization.max_height', 1600);

        [$width, $height] = $this->manager()->exposedTargetImageDimensions(800, 600);

        $this->assertSame(800, $width);
        $this->assertSame(600, $height);
    }

    protected function manager(): TestableUploadManager
    {
        return new TestableUploadManager();
    }
}

class TestableUploadManager extends UploadManager
{
    public function exposedTargetImageDimensions(int $width, int $height): array
    {
        return $this->targetImageDimensions($width, $height);
    }
}
