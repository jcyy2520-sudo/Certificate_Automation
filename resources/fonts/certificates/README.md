# Certificate fonts

Montserrat, Lato and Cormorant Garamond are bundled as static TrueType files.
Each family includes regular, bold, italic and bold italic. Its OFL license is
stored alongside its font files. `SOURCES.json` records the pinned upstream
commit URL and SHA-256 of each unmodified file. No runtime network font fetch is
needed by the browser or PDF renderer.

`metrics.json` records each face's hhea ascent/descent divided by unitsPerEm,
its PostScript name and its repository path. It also describes Dompdf's bundled
DejaVu faces. `CertificateFonts` supplies those same metrics to both renderers.
Update the metadata and source hashes whenever a font file is replaced.

The name's top coordinate anchors a one-em line box. Its baseline offset is
`size * (1 + ascent - descent) / 2`. The browser corrects its rounded line-box
baseline to this value; the PDF draws the name directly at this baseline after
rendering the unchanged artwork. Font-size fitting uses the full name and both
the available width and bottom boundary. Names are never shortened.

An artwork can define `name_max_width` and `name_bottom` in its template layout.
Those constraints are shared with Studio and apply even when a recipient has a
separate eight-field style snapshot. A replacement artwork clears the prior
image's constraints. Issued PDF files and delivery history are not rewritten.
