<?php

class MediaProcessor
{
  private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
  private const VIDEO_MIMES = ['video/mp4', 'video/quicktime'];

  public function process(string $sourcePath, string $mimeType, string $destinationPath): string
  {
    if (in_array($mimeType, self::IMAGE_MIMES, true)) {
      $this->processImage($sourcePath, $destinationPath);
      return 'photo';
    }

    if (in_array($mimeType, self::VIDEO_MIMES, true)) {
      $this->processVideo($sourcePath, $destinationPath);
      return 'video';
    }

    throw new InvalidArgumentException('Unsupported evidence media type');
  }

  private function processImage(string $sourcePath, string $destinationPath): void
  {
    $dimensions = @getimagesize($sourcePath);
    if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] * $dimensions[1] > 40000000) {
      throw new InvalidArgumentException('Invalid or oversized evidence image');
    }

    $filter = "scale=w='min(1000,iw)':h='min(1000,ih)':force_original_aspect_ratio=decrease,format=rgba,geq=r='r(X,Y)*alpha(X,Y)/255+255*(1-alpha(X,Y)/255)':g='g(X,Y)*alpha(X,Y)/255+255*(1-alpha(X,Y)/255)':b='b(X,Y)*alpha(X,Y)/255+255*(1-alpha(X,Y)/255)',format=yuvj420p";
    $this->run([
      $this->binary(),
      '-nostdin',
      '-v', 'error',
      '-threads', '1',
      '-i', $sourcePath,
      '-map_metadata', '-1',
      '-vf', $filter,
      '-frames:v', '1',
      '-q:v', '3',
      '-y', $destinationPath,
    ]);
  }

  private function processVideo(string $sourcePath, string $destinationPath): void
  {
    $filter = "scale=w='min(1280,iw)':h='min(1280,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2";
    $this->run([
      $this->binary(),
      '-nostdin',
      '-v', 'error',
      '-i', $sourcePath,
      '-map', '0:v:0',
      '-map', '0:a?',
      '-vf', $filter,
      '-c:v', 'libx264',
      '-preset', 'veryfast',
      '-crf', '28',
      '-pix_fmt', 'yuv420p',
      '-c:a', 'aac',
      '-b:a', '96k',
      '-movflags', '+faststart',
      '-map_metadata', '-1',
      '-f', 'mp4',
      '-y', $destinationPath,
    ]);
  }

  private function binary(): string
  {
    $binary = getenv('FFMPEG_BINARY');
    return $binary !== false && $binary !== '' ? $binary : 'ffmpeg';
  }

  private function run(array $command): void
  {
    $pipes = [];
    $process = proc_open($command, [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);

    if (!is_resource($process)) {
      throw new RuntimeException('Could not start FFmpeg');
    }

    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $errorOutput = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
      throw new RuntimeException('FFmpeg processing failed: ' . trim($errorOutput));
    }
  }
}
