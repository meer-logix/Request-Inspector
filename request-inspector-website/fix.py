import os
import re

html_files = [f for f in os.listdir('.') if f.endswith('.html')]
print('Processing:', len(html_files))

for f in html_files:
    with open(f, 'r', encoding='utf-8') as file:
        content = file.read()
    
    # Fix paths
    content = re.sub(r'href="/assets/', 'href="assets/', content)
    content = re.sub(r'src="/assets/', 'src="assets/', content)
    content = re.sub(r'href="/([a-zA-Z0-9_-]+\.html)"', r'href="\1"', content)
    content = re.sub(r'href="/site\.webmanifest"', 'href="site.webmanifest"', content)
    content = re.sub(r'href="/"', 'href="index.html"', content)
    
    with open(f, 'w', encoding='utf-8') as file:
        file.write(content)
        
print("Done!")
