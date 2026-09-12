import os
import re

html_files = [f for f in os.listdir('.') if f.endswith('.html')]
print('Processing:', len(html_files))

for f in html_files:
    with open(f, 'r', encoding='utf-8') as file:
        content = file.read()
    
    # Replace dummy logo with logo.png
    content = content.replace('assets/img/mark.svg', 'assets/img/logo.png')
    
    with open(f, 'w', encoding='utf-8') as file:
        file.write(content)
        
print("Logo replaced successfully!")
