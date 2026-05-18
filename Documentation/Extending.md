# Extending

The maintained extension-point documentation is
[Documentation/Developer/Extending.rst](Developer/Extending.rst).

Extensions that add filterable fields should expose them through the documented
filter metadata. The module can then defer free-text and configured filter
checks until after workspace overlays, including select/category filters and
plain text fields.

This Markdown file is a compatibility pointer for older links.
