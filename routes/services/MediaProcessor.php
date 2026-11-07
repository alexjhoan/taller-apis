<?php

class MediaProcessor
{
  private const MAX_INPUT_PIXELS = 40000000;
  private const MAX_IMAGE_DIMENSION = 1000;
  private const MAX_VIDEO_DIMENSION = 1280;

  public function process(string $sourcePath, string $mimeType, string $destinationPath): string
  {
    $probe = $this->probe($sourcePath, true);
    $videoStream = $this->videoStream($probe);
    $width = (int) ($videoStream['width'] ?? 0);
    $height = (int) ($videoStream['height'] ?? 0);
    if ($width < 1 || $height < 1 || $width * $height > self::MAX_INPUT_PIXELS) {
      throw new InvalidArgumentException('Invalid or oversized evidence media dimensions');
    }

    if ($this->isImage($mimeType, $probe)) {
      $this->processImage($sourcePath, $destinationPath);
      $this->verifyImage($destinationPath);
      return 'photo';
    }

    $this->processVideo($sourcePath, $destinationPath);
    $this->verifyVideo($destinationPath);
    return 'video';
  }

  private function processImage(string $sourcePath, string $destinationPath): void
  {
    $filter = "scale=w='min(" . self::MAX_IMAGE_DIMENSION . ",iw)':h='min(" . self::MAX_IMAGE_DIMENSION . ",ih)':force_original_aspect_ratio=decrease,format=rgba,geq=r='r(X,Y)*alpha(X,Y)/255+255*(1-alpha(X,Y)/255)':g='g(X,Y)*alpha(X,Y)/255+255*(1-alpha(X,Y)/255)':b='b(X,Y)*alpha(X,Y)/255+255*(1-alpha(X,Y)/255)',format=yuvj420p";
    $this->run([
      $this->binary(),
      '-nostdin',
      '-v', 'error',
      '-threads', '1',
      '-protocol_whitelist', 'file',
      '-i', $sourcePath,
      '-map', '0:v:0',
      '-map_metadata', '-1',
      '-vf', $filter,
      '-frames:v', '1',
      '-q:v', '3',
      '-y', $destinationPath,
    ]);
  }

  private function processVideo(string $sourcePath, string $destinationPath): void
  {
    $filter = "scale=w='min(" . self::MAX_VIDEO_DIMENSION . ",iw)':h='min(" . self::MAX_VIDEO_DIMENSION . ",ih)':force_original_aspect_ratio=decrease:force_divisible_by=2";
    $this->run([
      $this->binary(),
      '-nostdin',
      '-v', 'error',
      '-threads', '1',
      '-protocol_whitelist', 'file',
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

  private function isImage(string $mimeType, array $probe): bool
  {
    if (str_starts_with($mimeType, 'image/')) {
      return true;
    }

    if (str_starts_with($mimeType, 'video/')) {
      return false;
    }

    foreach ($probe['streams'] as $stream) {
      if (($stream['codec_type'] ?? null) === 'audio') {
        return false;
      }
    }

    return !isset($probe['format']['duration']) || (float) $probe['format']['duration'] <= 0;
  }

  private function probe(string $sourcePath, bool $uploadedInput = false): array
  {
    try {
      $output = $this->run([
        $this->probeBinary(),
        '-v', 'error',
        '-protocol_whitelist', 'file',
        '-show_entries', 'stream=codec_type,codec_name,width,height:format=format_name,duration',
        '-of', 'json',
        $sourcePath,
      ], 'FFprobe inspection');
    } catch (RuntimeException $error) {
      if ($uploadedInput) {
        throw new InvalidArgumentException('Unsupported or invalid evidence media', 0, $error);
      }
      throw $error;
    }

    try {
      $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
      throw new RuntimeException('Could not inspect evidence media metadata', 0, $error);
    }

    if (!is_array($result) || !is_array($result['streams'] ?? null)) {
      throw new InvalidArgumentException('Unsupported or invalid evidence media');
    }

    $this->videoStream($result);
    return $result;
  }

  private function videoStream(array $probe): array
  {
    foreach ($probe['streams'] ?? [] as $stream) {
      if (is_array($stream) && ($stream['codec_type'] ?? null) === 'video') {
        return $stream;
      }
    }

    throw new InvalidArgumentException('Evidence file does not contain an image or video stream');
  }

  private function verifyImage(string $path): void
  {
    $image = @getimagesize($path);
    if (
      $image === false || ($image[2] ?? null) !== IMAGETYPE_JPEG
      || $image[0] < 1 || $image[1] < 1
      || $image[0] > self::MAX_IMAGE_DIMENSION || $image[1] > self::MAX_IMAGE_DIMENSION
    ) {
      throw new RuntimeException('Processed evidence is not a valid JPEG within the size limit');
    }
  }

  private function verifyVideo(string $path): void
  {
    $probe = $this->probe($path);
    $stream = $this->videoStream($probe);
    $formatNames = (string) ($probe['format']['format_name'] ?? '');
    $duration = (float) ($probe['format']['duration'] ?? 0);
    if (
      ($stream['codec_name'] ?? null) !== 'h264'
      || (int) ($stream['width'] ?? 0) < 1 || (int) ($stream['height'] ?? 0) < 1
      || (int) $stream['width'] > self::MAX_VIDEO_DIMENSION
      || (int) $stream['height'] > self::MAX_VIDEO_DIMENSION
      || !str_contains($formatNames, 'mp4')
      || $duration <= 0
    ) {
      throw new RuntimeException('Processed evidence is not a valid MP4/H.264 video within the size limit');
    }
  }

  private function binary(): string
  {
    $binary = getenv('FFMPEG_BINARY');
    return $binary !== false && $binary !== '' ? $binary : 'ffmpeg';
  }

  private function probeBinary(): string
  {
    $binary = getenv('FFPROBE_BINARY');
    if ($binary !== false && $binary !== '') {
      return $binary;
    }

    $ffmpeg = $this->binary();
    if (preg_match('/^ffmpeg(?:\.exe)?$/i', $ffmpeg) === 1) {
      return preg_match('/\.exe$/i', $ffmpeg) === 1 ? 'ffprobe.exe' : 'ffprobe';
    }

    return preg_replace('/ffmpeg(\.exe)?$/i', 'ffprobe$1', $ffmpeg) ?? 'ffprobe';
  }

  private function run(array $command, string $operation = 'FFmpeg processing'): string
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
    $output = stream_get_contents($pipes[1]);
    $errorOutput = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
      throw new RuntimeException($operation . ' failed: ' . trim($errorOutput));
    }

    return $output;
  }
}
