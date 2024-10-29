import React, { PureComponent } from 'react';
import Switch from '@material-ui/core/Switch';
import FormControlLabel from '@material-ui/core/FormControlLabel';

const CustomSwitch = ({checked, onChange, name, color, label}) => {
    return ( 

        <div className="form-group">
            <FormControlLabel
                control={
                    <Switch
                        checked={checked}
                        onChange={onChange}
                        name={name}
                        color={color}
                    />
                }
                label={label}
            />
        </div>
     );
}
 
export default CustomSwitch;

    