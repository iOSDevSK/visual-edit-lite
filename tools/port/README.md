# Porting between Lite and Pro

`PRO_BASE` is the Visual Edit Pro commit whose generic changes this edition
carries. The process and the per-file classification live in Pro, in
`docs/developer/porting.md` and `tools/port/`. Pro→Lite never renames blindly:
only the text domain and the product name differ in shared files, and anything
from Pro's AI, licence, Turnstile or updater code stays out (`tools/build-plugin.sh`
refuses a build that carries it).
