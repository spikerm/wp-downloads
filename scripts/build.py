from pathlib import Path
import re, zipfile
root = Path(__file__).resolve().parents[1]
plugin = root / 'rex-downloads'
source = (plugin / 'rex-downloads.php').read_text()
version = re.search(r'\* Version: (\d+\.\d+\.\d+)', source).group(1)
assert f"__FILE__, '{version}'" in source, 'Updater version must match plugin header'
with zipfile.ZipFile(root / 'rex-downloads.zip','w',zipfile.ZIP_DEFLATED) as archive:
    for path in sorted(plugin.rglob('*')):
        if path.is_file(): archive.write(path,path.relative_to(root))
print(version)
