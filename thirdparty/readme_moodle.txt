Third-party libraries of local_aicoursebuilder
==============================================

The plugin has no Composer vendor directory. The libraries below are copied unmodified,
without their tests and documentation, and loaded by thirdparty/autoload.php.
The list with licences is in ../thirdpartylibs.xml. All licences are compatible with the
plugin licence (GPL v3 or later).

Library                 Version  Licence      Directory
smalot/pdfparser        2.12.5   LGPL-3.0     pdfparser/
phpoffice/phpword       1.4.0    LGPL-3.0     phpword/
phpoffice/math          0.3.0    MIT          phpoffice-math/
phpoffice/phppresentation 1.2.0  LGPL-3.0     phppresentation/
phpoffice/common        1.1.0    LGPL-3.0     phpoffice-common/

Notes
-----
* pdfparser needs ext-zlib and ext-iconv; it also lists symfony/polyfill-mbstring, which is not
  needed because ext-mbstring is a Moodle requirement.
* PhpWord needs ext-dom, ext-xml, ext-zip, ext-gd and ext-json. Only the reader is used (the
  DOCX extractor). PhpWord ships PCLZip (LGPL 2.1 or later) in Shared/PCLZip; it is not used
  because ext-zip is present.
* The PhpWord optional writers (Dompdf, TCPDF) are not used.
* PhpPresentation needs ext-xml and ext-zip, phpoffice/common and PhpSpreadsheet. PhpSpreadsheet is
  not copied here: Moodle core bundles it (lib/phpspreadsheet) and the XLSX extractor uses that copy.
  Only the PhpPresentation reader is used (the PPTX extractor); the copy has no VERSION file
  because the one in the release archive is out of date.

Update procedure
----------------
1. Check the new release: licence still LGPL/MIT, PHP requirement <= 8.3, dependencies unchanged
   (composer.json "require" of each library).
2. Download the release archive from the repository in thirdpartylibs.xml.
3. Replace the directory: pdfparser/ gets LICENSE.txt and src/ from the archive; phpword/,
   phppresentation/ and phpoffice-common/ get COPYING, COPYING.LESSER, LICENSE and src/;
   phpoffice-math/ gets LICENSE and src/.
4. If a library adds a dependency or changes its namespace root, update the map in autoload.php.
5. Update the version in thirdpartylibs.xml and in the table above.
6. Run the PHPUnit suite (tests/ingest) and lint: php -l on the new files.
