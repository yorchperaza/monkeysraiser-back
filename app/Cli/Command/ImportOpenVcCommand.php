<?php

declare(strict_types=1);

namespace App\Cli\Command;

use App\Entity\Media;
use App\Entity\OpenVcInvestor;
use App\Repository\MediaRepository;
use App\Repository\OpenVcInvestorRepository;
use MonkeysLegion\Cli\Console\Attributes\Command as CommandAttr;
use MonkeysLegion\Cli\Console\Command;

#[CommandAttr('app:import-openvc', 'Import OpenVC investors from CSV')]
class ImportOpenVcCommand extends Command
{
    public function __construct(
        private OpenVcInvestorRepository $repo,
        private MediaRepository $mediaRepo
    ) {
        parent::__construct();
    }

    protected function handle(): int
    {
        $csvPath = base_path('fundraisingimport/openvc_detailed_results.csv');
        if (!file_exists($csvPath)) {
            $this->error("CSV file not found at: $csvPath");
            return self::FAILURE;
        }

        $logoBaseDir = base_path('fundraisingimport');
        
        // Check for --fresh flag
        $fresh = in_array('--fresh', $_SERVER['argv'] ?? []);
        
        if ($fresh) {
            $this->info("🗑️  Fresh import: Deleting all existing investors and logos...");
            
            // Get all investor logo IDs first
            $investors = $this->repo->findAll();
            $logoIds = [];
            foreach ($investors as $inv) {
                if ($inv->logo_id) {
                    $logoIds[] = $inv->logo_id;
                }
            }
            
            // Delete all investors
            foreach ($investors as $inv) {
                $this->repo->delete($inv);
            }
            $this->info("   Deleted " . count($investors) . " investors.");
            
            // Delete related media (logos)
            foreach ($logoIds as $logoId) {
                $media = $this->mediaRepo->find($logoId);
                if ($media) {
                    $this->mediaRepo->delete($media);
                }
            }
            $this->info("   Deleted " . count($logoIds) . " logo media entries.");
            $this->info("");
        }
        
        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            $this->error("Could not open CSV file.");
            return self::FAILURE;
        }

        // Get headers
        $headers = fgetcsv($handle, 0, ',', '"', '\\');

        $count = 0;
        $updated = 0;
        
        $this->info("Starting import...");

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (count($row) < 5) continue; // Skip empty/malformed lines

            $fundName = $row[0];
            if (empty($fundName)) continue;

            // Check if exists
            $existing = $this->repo->findOneBy(['fundName' => $fundName]);
            
            if ($existing) {
                // Determine if we update. For now, let's update everything.
                $investor = $existing;
                $updated++;
                echo ".";
            } else {
                $investor = new OpenVcInvestor();
                $investor->setFundName($fundName);
                $count++;
                echo "+";
            }
            
            $verified = strtolower($row[1]) === 'true';
            $investor->setVerified($verified);
            
            // Logo - always try to assign if path exists
            $localLogoPath = $row[3] ?? null;
            if ($localLogoPath) {
                $fullLogoPath = $logoBaseDir . '/' . $localLogoPath;
                $filename = basename($localLogoPath);
                $publicUrl = '/uploads/logos/' . $filename;
                
                if (file_exists($fullLogoPath)) {
                    // Check if media with this URL already exists
                    $existingMedia = $this->mediaRepo->findOneBy(['url' => $publicUrl]);
                    
                    if ($existingMedia) {
                        // Reuse existing media
                        $investor->setLogo($existingMedia);
                        $investor->logo_id = $existingMedia->getId();
                    } else {
                        // Create new Media
                        $media = new Media();
                        $uploadDir = base_path('public/uploads/logos');
                        
                        // Ensure directory exists
                        if (!is_dir($uploadDir)) {
                            // Check if parent is writable
                            $parent = dirname($uploadDir);
                            if (!is_writable($parent)) {
                                $this->error("Permission denied: Cannot create directory '$uploadDir'. The parent directory '$parent' is not writable.");
                                return self::FAILURE; // Exit with error
                            }
                            
                            if (!mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
                                $this->error("Failed to create directory '$uploadDir'. Check permissions.");
                                return self::FAILURE; 
                            }
                        }

                        // Ensure directory is writable
                        if (!is_writable($uploadDir)) {
                             $this->error("Permission denied: Directory '$uploadDir' is not writable. Please run: chmod 777 $uploadDir");
                             return self::FAILURE;
                        }

                        $targetPath = $uploadDir . '/' . $filename;
                        
                        if (!file_exists($targetPath)) {
                            if (!copy($fullLogoPath, $targetPath)) {
                                $this->error("Failed to copy logo to '$targetPath'.");
                                // We can continue or exit, let's continue but log it
                            }
                        }
                        
                        $media->setUrl($publicUrl);
                        $media->setType('image/jpeg');
                        $media->setCreated(new \DateTimeImmutable());
                        
                        $this->mediaRepo->save($media);
                        $investor->setLogo($media);
                        $investor->logo_id = $media->getId();
                    }
                }
            }

            $investor->setLinkedin(empty($row[4]) ? null : $row[4]);
            $investor->setWebsite(empty($row[5]) ? null : $row[5]);
            $investor->setDescription(empty($row[6]) ? null : $row[6]);
            $investor->setValueAdd(empty($row[7]) ? null : $row[7]);
            
            // Firm Type (JSON)
            $firmTypeRaw = $row[8] ?? null;
            if ($firmTypeRaw && $firmTypeRaw !== 'N/A') {
                $investor->setFirmType([$firmTypeRaw]);
            } else {
                $investor->setFirmType(null);
            }

            $investor->setGlobalHq(empty($row[9]) ? null : $row[9]);

            $stagesRaw = $row[16] ?? null;
            if ($stagesRaw && $stagesRaw !== 'N/A') {
                $stages = array_map('trim', explode(';', $stagesRaw));
                // Remove leading numbers like "4. Scaling" -> "Scaling"
                $stages = array_map(function($stage) {
                    return preg_replace('/^\d+\.\s*/', '', $stage);
                }, $stages);
                $investor->setFundingStages($stages);
            }

            $min = isset($row[18]) && is_numeric($row[18]) ? (int)$row[18] : null;
            $max = isset($row[19]) && is_numeric($row[19]) ? (float)$row[19] : null; // Use float for initial parse of potential huge numbers

            // Max int limit (2,147,483,647) logic
            if ($min !== null && $min > 2147483647) {
                $min = 0;
            }
            if ($max !== null && $max > 2147483647) {
                $max = 0;
            }

            // Cast back to int/null for setter
            $investor->setCheckSizeMin($min !== null ? (int)$min : null);
            $investor->setCheckSizeMax($max !== null ? (int)$max : null);

            $countriesRaw = $row[21] ?? null;
             if ($countriesRaw && $countriesRaw !== 'N/A') {
                $countries = array_map('trim', explode(';', $countriesRaw));
                $investor->setTargetCountries($countries);
            }

            $investor->setTeam(empty($row[22]) ? null : $row[22]);
            $investor->setSourcePage(empty($row[23]) ? null : $row[23]);

            $this->repo->save($investor);
        }

        fclose($handle);
        $this->info("\nImport complete. Created: $count, Updated: $updated");
        return self::SUCCESS;
    }
}
